<?php

namespace App\Jobs;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\ImageProcessingService;
use App\Services\OneDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fetch a photo that is already right and put it where the push can find it.
 *
 * The sibling of EditPhotoItemJob for the photos nobody wants edited. It ends
 * in the same place — status 'edited', a file on disk, thumbnails for the
 * review screen — so everything downstream treats the two identically and the
 * operator picks and pushes one grid rather than two.
 *
 * What it deliberately does not do is anything to the pixels. No cutout, no
 * canvas, no re-encoding to hit a byte target: "as is" is the whole request,
 * and a photo quietly recompressed on its way past would not be as it was.
 * The only exception is the EXIF orientation flag, which is honoured rather
 * than applied — a camera writing "this is on its side" is describing the file,
 * not editing it, and Shopify would show it sideways otherwise.
 */
class CopyOriginalPhotoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;
    public int $backoff = 30;

    public function __construct(
        public readonly int $itemId,
    ) {}

    public function handle(OneDriveService $oneDrive, ImageProcessingService $imageService): void
    {
        $item = PhotoEditItem::find($this->itemId);

        if (!$item || $item->status !== 'pending') {
            return;
        }

        $session = PhotoEditSession::find($item->photo_edit_session_id);

        if (!$session) {
            return;
        }

        $item->update(['status' => 'editing']);

        if ($session->user_id && ($user = User::find($session->user_id))) {
            $oneDrive->setUser($user);
        }

        try {
            $raw = $oneDrive->downloadFileById(
                $item->onedrive_drive_id,
                $item->onedrive_item_id,
                $item->onedrive_download_url ?? '',
            );

            $originalKb = (int) round(strlen($raw) / 1024);

            $raw = $imageService->normalizeOrientation($raw);

            /*
             * Shopify refuses anything past 20 megapixels outright, whatever
             * the file size. A photo that would be rejected on upload is worse
             * than one resized here, and this is the only circumstance in which
             * an untouched photo is touched at all.
             */
            $raw = $imageService->capPixelCountPreservingAlpha($raw, $this->extension($item->filename));

            $dir = $session->absoluteStorageDir();

            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Could not create storage directory for session {$session->id}.");
            }

            $ext       = $this->extension($item->filename);
            $editedRel = $session->storageDir() . "/{$item->id}-after.{$ext}";
            $beforeRel = $session->storageDir() . "/{$item->id}-before.jpg";
            $thumbRel  = $session->storageDir() . "/{$item->id}-after-thumb.jpg";

            file_put_contents(storage_path('app/' . $editedRel), $raw);

            // Both thumbnails come from the same bytes, because both sides of
            // the comparison are the same photograph. Shown rather than hidden:
            // "nothing happened here" is worth being able to see.
            $thumb = $imageService->thumbnail($raw, 420);
            file_put_contents(storage_path('app/' . $beforeRel), $thumb);
            file_put_contents(storage_path('app/' . $thumbRel), $thumb);

            unset($raw, $thumb);

            $item->update([
                'status'              => 'edited',
                'original_thumb_path' => $beforeRel,
                'edited_thumb_path'   => $thumbRel,
                'edited_path'         => $editedRel,
                'original_size_kb'    => $originalKb,
                'edited_size_kb'      => (int) round(filesize(storage_path('app/' . $editedRel)) / 1024),
                'error_message'       => null,
            ]);

            $session->increment('edited_files');

        } catch (\Throwable $e) {
            Log::error("CopyOriginalPhotoJob item {$this->itemId} failed: " . $e->getMessage());

            $item->update([
                'status'        => 'failed',
                'error_message' => 'Could not fetch the original: ' . $e->getMessage(),
            ]);

            $session->increment('failed_files');
        }
    }

    /** The file's own extension, since nothing here changes the format. */
    private function extension(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'], true) ? $ext : 'jpg';
    }
}
