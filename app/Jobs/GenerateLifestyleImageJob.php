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

    private const MAX_OUTPUT_BYTES = 4_000_000;

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

            $edited = $photoroom->edit($input, $this->onModelEdits($edits), $item->filename);
            unset($input);

            // A generated scene is always opaque, so it is always a JPEG and
            // always safe to compress.
            $edited = $imageService->compressOnly($edited, self::MAX_OUTPUT_BYTES);

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

            'lighting'    => $edits['lighting'] ?? null,
            'export_format' => 'jpg',
            'color_space' => $edits['color_space'] ?? 'sRGB',
        ];
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
