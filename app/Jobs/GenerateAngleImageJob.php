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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ask Photoroom for a view of a product it has only seen from one side.
 *
 * Edit With AI takes a prompt naming the view, and Photoroom's own
 * documentation says it "generates new images rather than rotating existing
 * ones". That sentence is the whole feature: what comes back is the model's
 * idea of that side, and the further it is from the view that was shot, the
 * more of it is invented. A sole pattern, a heel counter, a logo on a back
 * panel — none of those are derivable from a top view.
 *
 * So every one of these is badged as generated on the review screen and none
 * is selected for pushing by default. The same pass reinvented an Aigner
 * horseshoe monogram as rings on this account, on a job that only had to
 * remove a stand from a photo it could see.
 *
 * One credit each.
 */
class GenerateAngleImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;
    public int $backoff = 30;

    private const MAX_OUTPUT_BYTES = 1_000_000;

    public function __construct(
        public readonly int $itemId,
        public readonly string $angle,
    ) {}

    public function handle(
        OneDriveService $oneDrive,
        ImageProcessingService $imageService,
        PhotoroomService $photoroom,
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
                'error_message' => 'The photo this angle was to be generated from is no longer there.',
            ]);

            return;
        }

        $item->update(['status' => 'editing']);

        try {
            if ($session->user_id && ($user = User::find($session->user_id))) {
                $oneDrive->setUser($user);
            }

            $raw = filled($source->source_url)
                ? (string) Http::timeout(60)->get($source->source_url)->body()
                : $oneDrive->downloadFileById(
                    $source->onedrive_drive_id,
                    $source->onedrive_item_id,
                    $source->onedrive_download_url ?? '',
                );

            $raw = $imageService->capBitDepth($imageService->normalizeOrientation($raw));

            $prompt = \App\Models\PhotoEditGroup::ANGLES[$this->angle]
                ?? throw new \RuntimeException("No prompt for the '{$this->angle}' view.");

            /*
             * A seed tied to the item, so a disappointing angle can be looked
             * at again rather than re-rolled into a different disappointment.
             * Without one this pass draws something new every run, and "it was
             * better last time" is not a thing anyone can act on.
             */
            $generated = $photoroom->eraseObject($raw, $prompt, $item->filename, $item->id);

            $edits  = $item->resolvedEdits();
            $edited = $photoroom->edit($generated, $this->cutoutEdits($edits), $item->filename);

            if (!empty($edits['framing_preset']) && !empty($edits['width'])) {
                $edited = $imageService->frameToStandard(
                    $edited,
                    (int) $edits['width'],
                    (float) ($edits['padding'] ?? 0.10),
                    isset($edits['padding_bottom']) ? (float) $edits['padding_bottom'] : null,
                    (string) ($edits['v_align'] ?? 'center'),
                );
            }

            $edited = $imageService->toJpegUnderLimit($edited, self::MAX_OUTPUT_BYTES);

            $dir = storage_path('app/' . $session->storageDir());

            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Could not create storage directory for session {$session->id}.");
            }

            $rel   = $session->storageDir() . "/{$item->id}-after.jpg";
            $thumb = $session->storageDir() . "/{$item->id}-after-thumb.jpg";

            file_put_contents(storage_path('app/' . $rel), $edited);
            file_put_contents(storage_path('app/' . $thumb), $imageService->thumbnail($edited, 420));

            $item->update([
                'status'               => 'edited',
                'edited_path'          => $rel,
                'edited_thumb_path'    => $thumb,
                'edited_size_kb'       => (int) round(strlen($edited) / 1024),
                'apparel_mode_applied' => 'generated_angle',
                'photoroom_requests'   => 2,
            ]);
        } catch (\Throwable $e) {
            Log::warning("GenerateAngleImageJob item {$this->itemId}: " . $e->getMessage());

            $item->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    /** The second pass: a plain cutout of whatever the first one drew. */
    private function cutoutEdits(array $edits): array
    {
        $edits['ghost_mannequin']   = false;
        $edits['flat_lay']          = false;
        $edits['virtual_model']     = false;
        $edits['remove_background'] = true;
        $edits['segmentation_prompt'] = null;

        return $edits;
    }

    public function failed(\Throwable $e): void
    {
        PhotoEditItem::where('id', $this->itemId)->update([
            'status'        => 'failed',
            'error_message' => 'Max retries reached: ' . $e->getMessage(),
        ]);
    }
}
