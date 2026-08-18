<?php

namespace App\Jobs;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\OneDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Read a OneDrive folder and queue one Photoroom edit per image found.
 *
 * Deliberately separate from ScanOneDriveFolderJob: that one warms the Shopify
 * SKU cache and dispatches straight to upload, because a bulk upload always
 * ends at Shopify. This one ends at a review screen, and every image it queues
 * costs money, so the shape of the work is different enough not to share.
 */
class ScanPhotoEditFolderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries   = 2;

    private const INSERT_CHUNK = 200;

    public function __construct(
        public readonly int $sessionId,
    ) {}

    public function handle(OneDriveService $oneDrive): void
    {
        $session = PhotoEditSession::findOrFail($this->sessionId);

        $session->update(['scan_status' => 'scanning', 'status' => 'processing']);

        if ($session->user_id && ($user = User::find($session->user_id))) {
            $oneDrive->setUser($user);
        }

        $cap = max(1, (int) config('services.photoroom.max_images', 120));

        try {
            $total  = 0;
            $buffer = [];

            $oneDrive->streamFolderImages(
                $session->onedrive_link,
                function (array $file) use ($session, &$buffer, &$total, $cap) {
                    /*
                     * Photoroom bills per image. A link pasted one folder too
                     * high can hold thousands, and by the time anyone notices
                     * the money is already spent — so refuse the whole run
                     * before a single edit is queued, rather than editing the
                     * first $cap and leaving someone to guess what was skipped.
                     */
                    if ($total >= $cap) {
                        throw new \RuntimeException(
                            "This folder holds more than {$cap} images. Photoroom charges for every image it edits, "
                            . "so nothing was sent — point at a smaller folder, or raise PHOTOROOM_MAX_IMAGES."
                        );
                    }

                    $identifier = !empty($file['folder_name'])
                        ? $file['folder_name']
                        : pathinfo($file['filename'], PATHINFO_FILENAME);

                    $buffer[] = [
                        'photo_edit_session_id' => $session->id,
                        'filename'              => $file['filename'],
                        'sku_detected'          => $this->normalizeIdentifier($identifier),
                        'onedrive_drive_id'     => $file['drive_id'],
                        'onedrive_item_id'      => $file['item_id'],
                        'onedrive_download_url' => $file['download_url'] ?? '',
                        'original_size_kb'      => (int) round(($file['size_bytes'] ?? 0) / 1024),
                        'status'                => 'pending',
                        'selected'              => true,
                        'created_at'            => now(),
                        'updated_at'            => now(),
                    ];

                    $total++;

                    if (count($buffer) >= self::INSERT_CHUNK) {
                        PhotoEditItem::insert($buffer);
                        $buffer = [];
                        $session->increment('scanned_files', self::INSERT_CHUNK);
                    }
                }
            );

            if ($buffer) {
                PhotoEditItem::insert($buffer);
                $session->increment('scanned_files', count($buffer));
            }

            $session->update([
                'scan_status' => 'scanned',
                'total_files' => $total,
            ]);

            if ($total === 0) {
                $session->update([
                    'status'        => 'completed',
                    'error_message' => 'No images were found in that OneDrive folder.',
                ]);
                return;
            }

            Log::info("ScanPhotoEditFolderJob: {$total} images queued for session {$this->sessionId}");

            PhotoEditItem::where('photo_edit_session_id', $session->id)
                ->where('status', 'pending')
                ->select('id')
                ->chunkById(500, function ($items) {
                    foreach ($items as $item) {
                        try {
                            EditPhotoItemJob::dispatch($item->id)->onQueue('bulkupload');
                        } catch (\Throwable $e) {
                            Log::error("ScanPhotoEditFolderJob: dispatch failed for item {$item->id}: " . $e->getMessage());
                        }
                    }
                });

        } catch (\Throwable $e) {
            Log::error("ScanPhotoEditFolderJob failed for session {$this->sessionId}: " . $e->getMessage());

            // Nothing has been sent to Photoroom yet, so the rows scanned so far
            // describe work that will never happen — clear them out rather than
            // leaving a failed session full of phantom items.
            PhotoEditItem::where('photo_edit_session_id', $this->sessionId)->delete();

            $session->update([
                'scan_status'   => 'failed',
                'status'        => 'failed',
                'total_files'   => 0,
                'scanned_files' => 0,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        PhotoEditSession::where('id', $this->sessionId)->update([
            'scan_status'   => 'failed',
            'status'        => 'failed',
            'error_message' => 'Scan failed: ' . $e->getMessage(),
        ]);
    }

    /**
     * Only the part before the first "_", "-" or "." is the identifier — the
     * rest is the suffix OneDrive names carry ("_var1", "-2", ".jpg"). Matches
     * how the bulk uploader reads the same folders.
     */
    private function normalizeIdentifier(string $raw): string
    {
        $name = trim($raw);
        $cut  = strcspn($name, '_-.');

        return $cut > 0 ? substr($name, 0, $cut) : $name;
    }
}
