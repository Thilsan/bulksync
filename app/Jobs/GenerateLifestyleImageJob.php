<?php

namespace App\Jobs;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\ImageProcessingService;
use App\Services\OneDriveService;
use App\Services\PhotoroomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Build one on-model image for a SKU from one of its real photographs.
 *
 * Separate from EditPhotoItemJob because the two produce different things and
 * must not be confused: that one edits a photograph of the product, this one
 * asks Photoroom to invent a person wearing it. The result is marketing
 * imagery. It is never the record of what the product looks like, which is why
 * it is stored with kind='lifestyle' and counted apart.
 *
 * Each call is billed, so a group asking for three shots costs three credits
 * on top of its cutouts.
 */
class GenerateLifestyleImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // generating a scene is slower than a cutout
    public int $tries   = 2;
    public int $backoff = 30;

    private const MAX_OUTPUT_BYTES = 1_000_000;

    /**
     * The square every finished image in the catalogue is built on, on-model
     * shots included — an on-model image that arrived smaller than the
     * photographs beside it would be the one tile in the row that looked wrong.
     */
    private const CANVAS_EDGE = 2000;

    public function __construct(
        public readonly int $itemId,
        /** Which of the group's requested shots this is, so each varies. */
        public readonly int $variationIndex = 0,
    ) {}

    public function handle(
        OneDriveService        $oneDrive,
        ImageProcessingService $imageService,
        PhotoroomService       $photoroom,
    ): void {
        $item = PhotoEditItem::find($this->itemId);

        if (!$item || in_array($item->status, ['edited', 'pushed', 'skipped'], true)) {
            return;
        }

        $session = PhotoEditSession::find($item->photo_edit_session_id);
        $source  = $item->source_item_id ? PhotoEditItem::find($item->source_item_id) : null;

        if (!$session || !$source) {
            $item->update([
                'status'        => 'failed',
                'error_message' => 'The photo this on-model image was to be built from is no longer there.',
            ]);

            return;
        }

        $item->update(['status' => 'editing']);

        if ($session->user_id && ($user = User::find($session->user_id))) {
            $oneDrive->setUser($user);
        }

        $edits = $item->resolvedEdits();

        try {
            $raw = $oneDrive->downloadFileById(
                $source->onedrive_drive_id,
                $source->onedrive_item_id,
                $source->onedrive_download_url ?? '',
            );

            $raw = $imageService->normalizeOrientation($raw);
            $raw = $imageService->rotate(
                $raw,
                (string) ($edits['input_rotation'] ?? ''),
                !empty($edits['rotate_wide_only']),
            );

            $input = $this->fitForPhotoroom($raw, $imageService);
            unset($raw);

            $dir = $session->absoluteStorageDir();

            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Could not create storage directory for session {$session->id}.");
            }

            $beforeRel = $session->storageDir() . "/{$item->id}-before.jpg";
            file_put_contents(storage_path('app/' . $beforeRel), $imageService->thumbnail($input, 420));

            $edited = $this->generate($photoroom, $input, $edits, $item->filename);
            unset($input);

            /*
             * A generated scene is always opaque, so it is always kept as a
             * JPEG — but Photoroom is asked for PNG so its fixed quality-80
             * export never touches it, which means the bytes that arrive are
             * not a JPEG yet. Encoding here is what makes the .jpg on disk
             * true, at the best quality that fits and without ever trading
             * pixels for bytes.
             */
            $edited = $imageService->toJpegUnderLimit($edited, self::MAX_OUTPUT_BYTES);

            $editedRel = $session->storageDir() . "/{$item->id}-after.jpg";
            $thumbRel  = $session->storageDir() . "/{$item->id}-after-thumb.jpg";

            file_put_contents(storage_path('app/' . $editedRel), $edited);
            file_put_contents(storage_path('app/' . $thumbRel), $imageService->thumbnail($edited, 420));

            $item->update([
                'status'               => 'edited',
                'original_thumb_path'  => $beforeRel,
                'edited_path'          => $editedRel,
                'edited_thumb_path'    => $thumbRel,
                'edited_size_kb'       => (int) round(strlen($edited) / 1024),
                'error_message'        => null,
                'apparel_mode_applied' => 'on_model',
                'uncertainty_score'    => $photoroom->lastUncertaintyScore(),
            ]);

        } catch (\Throwable $e) {
            Log::error("GenerateLifestyleImageJob item {$this->itemId} failed: " . $e->getMessage());

            $item->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        PhotoEditItem::where('id', $this->itemId)->update([
            'status'        => 'failed',
            'error_message' => 'Max retries reached: ' . $e->getMessage(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────

    /**
     * Force the on-model settings on, whatever the group's own edits say.
     *
     * The group's settings describe how its cutouts should look — a white
     * background, a fixed canvas. Applied here they would fight the generated
     * scene, so only the parts that still make sense are carried over.
     *
     * Each shot in a group also varies: asking for three identical prompts
     * returns three near-identical images, which is not what "three lifestyle
     * shots" means to anyone.
     */
    private function onModelEdits(array $edits): array
    {
        $scenes = PhotoroomService::VIRTUAL_MODEL_SCENES;
        $poses  = PhotoroomService::VIRTUAL_MODEL_POSES;

        $scene = $edits['vm_scene'] ?? '';
        $pose  = $edits['vm_pose']  ?? '';

        return [
            'virtual_model'   => true,
            'ghost_mannequin' => false,
            'flat_lay'        => false,

            'vm_model' => $edits['vm_model'] ?? '',

            // A chosen scene or pose is honoured on the first shot and varied
            // after it; left unset, every shot varies.
            'vm_scene' => $this->vary($scene, $scenes),
            'vm_pose'  => $this->vary($pose, $poses),

            'vm_model_url'          => $edits['vm_model_url'] ?? null,
            'vm_scene_url'          => $edits['vm_scene_url'] ?? null,
            'vm_extra_product_urls' => $edits['vm_extra_product_urls'] ?? [],

            'apparel_size'   => $edits['apparel_size'] ?? null,
            'apparel_prompt' => $edits['apparel_prompt'] ?? null,

            /*
             * Generation decides its own dimensions from the size preset, and
             * they come out below the 2000 square the catalogue is built on.
             * outputSize cannot fix that — asking for the picture at one shape
             * and then forcing it into another is what makes a soft, stretched
             * result, which is why applyLayout refuses to send it here.
             *
             * Photoroom's own upscaler can, and it redraws rather than
             * stretches. Inventing detail would be indefensible on a photograph
             * of a real bag; on a model who does not exist, in a room that does
             * not exist, there is no real detail to be unfaithful to. The whole
             * frame is generated either way.
             */
            'upscale'            => true,
            'upscale_resolution' => self::CANVAS_EDGE,

            'lighting'    => $edits['lighting'] ?? null,
            'export_format' => 'jpg',
            'color_space' => $edits['color_space'] ?? 'sRGB',
        ];
    }

    /**
     * Generate the scene, upscaled to the catalogue's canvas if Photoroom will.
     *
     * The 400 naming upscale/mode that this was written around was not, it
     * turns out, Photoroom refusing to combine the upscaler with generation.
     * The mode being sent was "ai.auto", which v2 does not accept from anyone
     * for anything — so this retry fired on every single on-model shot, and
     * none has ever reached the catalogue canvas. With a real mode value the
     * first attempt now has a chance of standing.
     *
     * The retry stays regardless. Whether the two can be combined is still
     * undocumented, and an on-model shot at the size generation chose is worth
     * having: losing the whole image over an optional enhancement is the wrong
     * way round.
     *
     * Deliberately narrow. Only a rejection that names upscale is retried, and
     * only once — a 429 or a credit problem is not something a second identical
     * request improves, and it would cost another credit to find that out.
     */
    private function generate(PhotoroomService $photoroom, string $input, array $edits, string $filename): string
    {
        $wanted = $this->onModelEdits($edits);

        try {
            return $photoroom->edit($input, $wanted, $filename);
        } catch (\Throwable $e) {
            if (!str_contains(strtolower($e->getMessage()), 'upscale')) {
                throw $e;
            }

            Log::info('Photoroom refused the upscale; retrying at the size generation chooses.', [
                'item'  => $this->itemId,
                'error' => $e->getMessage(),
            ]);

            unset($wanted['upscale'], $wanted['upscale_resolution']);

            return $photoroom->edit($input, $wanted, $filename);
        }
    }

    /**
     * The chosen value for the first shot, then a different one for each after
     * it — walking the list rather than picking at random, so a re-run of the
     * same group reproduces the same set.
     */
    private function vary(string $chosen, array $options): string
    {
        $usable = array_values(array_diff($options, ['random']));

        if ($this->variationIndex === 0) {
            return $chosen;
        }

        if (!$usable) {
            return $chosen;
        }

        $start = $chosen !== '' ? (int) array_search($chosen, $usable, true) : 0;

        return $usable[($start + $this->variationIndex) % count($usable)];
    }

    private function fitForPhotoroom(string $content, ImageProcessingService $imageService): string
    {
        $info    = @getimagesizefromstring($content);
        $tooWide = $info && max((int) $info[0], (int) $info[1]) > PhotoroomService::MAX_INPUT_EDGE;

        if (!$tooWide && strlen($content) <= PhotoroomService::MAX_INPUT_BYTES) {
            return $content;
        }

        return $imageService->scaleDownForAnalysis(
            $content,
            PhotoroomService::MAX_INPUT_EDGE,
            PhotoroomService::MAX_INPUT_BYTES,
        );
    }
}
