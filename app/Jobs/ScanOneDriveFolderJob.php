<?php

namespace App\Jobs;

use App\Models\UploadItem;
use App\Models\UploadSession;
use App\Services\OneDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScanOneDriveFolderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;  // 1 hour — scanning 30k files takes time
    public int $tries   = 2;

    private const INSERT_CHUNK = 500; // rows per bulk insert

    /** Below this, a filename is too generic to be worth trying as a SKU. */
    private const MIN_FALLBACK_LENGTH = 4;

    public function __construct(
        public readonly int $sessionId,
    ) {}

    public function handle(OneDriveService $oneDrive): void
    {
        $session = UploadSession::findOrFail($this->sessionId);

        $session->update(['scan_status' => 'scanning', 'status' => 'processing']);

        Log::info("ScanOneDriveFolderJob: starting scan for session {$this->sessionId}");

        if ($session->user_id) {
            $user = \App\Models\User::find($session->user_id);
            if ($user) {
                $oneDrive->setUser($user);
            }
        }

        try {
            $totalScanned = 0;
            $buffer       = [];

            // Stream through OneDrive pages one at a time — never load all 30k into memory
            $oneDrive->streamFolderImages(
                $session->onedrive_link,
                function (array $file) use ($session, &$buffer, &$totalScanned) {
                    ['primary' => $sku, 'fallback' => $filenameSku] = self::identifiersFor(
                        $file['folder_name'] ?? '',
                        $file['filename'],
                    );

                    $buffer[] = [
                        'upload_session_id'    => $session->id,
                        'filename'             => $file['filename'],
                        'sku_detected'         => $sku,
                        'filename_sku'         => $filenameSku,
                        'onedrive_drive_id'    => $file['drive_id'],
                        'onedrive_item_id'     => $file['item_id'],
                        'onedrive_download_url'=> $file['download_url'] ?? '',
                        'original_size_kb'     => (int) round(($file['size_bytes'] ?? 0) / 1024),
                        'status'               => 'pending',
                        'created_at'           => now(),
                        'updated_at'           => now(),
                    ];

                    $totalScanned++;

                    // Flush to DB every INSERT_CHUNK items to keep memory flat
                    if (count($buffer) >= self::INSERT_CHUNK) {
                        $this->flushBuffer($session, $buffer);
                        $buffer = [];

                        // Update live scan count so the UI can show progress during scan
                        $session->increment('scanned_files', self::INSERT_CHUNK);
                        Log::info("ScanOneDriveFolderJob: {$totalScanned} files found so far…");
                    }
                }
            );

            // Flush any remaining items
            if (!empty($buffer)) {
                $this->flushBuffer($session, $buffer);
                $session->increment('scanned_files', count($buffer));
            }

            $session->update([
                'scan_status' => 'scanned',
                'total_files' => $totalScanned,
            ]);

            Log::info("ScanOneDriveFolderJob: scan complete — {$totalScanned} files.");

            // No SKU cache warm here any more. ProcessUploadItemJob asks Shopify
            // live for every item, so warming bought the upload nothing while
            // costing it up to an hour of dead wait before the first image moved.
            // The SKU Checker still uses the cache; the schedule keeps it warm.

            // Dispatch a ProcessUploadItemJob for every pending item in chunks
            // to avoid loading all 30k models at once.
            // Wrap each dispatch so a single failing item doesn't abort the rest
            // (sync queue re-throws on failure, which would bubble up otherwise).
            UploadItem::where('upload_session_id', $session->id)
                ->where('status', 'pending')
                ->select('id')
                ->chunkById(1000, function ($items) {
                    foreach ($items as $item) {
                        try {
                            ProcessUploadItemJob::dispatch($item->id)
                                ->onQueue('bulkupload');
                        } catch (\Throwable $e) {
                            Log::error("ScanOneDriveFolderJob: dispatch failed for item {$item->id}: " . $e->getMessage());
                        }
                    }
                });

        } catch (\Throwable $e) {
            Log::error("ScanOneDriveFolderJob failed for session {$this->sessionId}: " . $e->getMessage());
            $session->update([
                'scan_status'   => 'failed',
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────

    private function flushBuffer(UploadSession $session, array $buffer): void
    {
        UploadItem::insert($buffer);
    }

    /**
     * The two places a SKU can be written, in the order matching should try them.
     *
     * Photos arrive both ways: a folder per SKU holding several shots, and a
     * flat folder of files each named after its own SKU. The folder name is
     * still tried first — with a folder per SKU it is the reliable one, since
     * the files inside are usually named after barcodes or marketing copy. But
     * a folder named for the shipment rather than the item ("Lancome Aug") used
     * to bury the filename SKU entirely and turn every file into a No Match, so
     * the filename is kept as a fallback whenever the two disagree.
     *
     * 'fallback' is null when there is nothing new to try — no folder to begin
     * with, both names agreeing, or a filename too short to be a SKU worth
     * guessing at ("1.jpg" would otherwise go looking for a variant called 1).
     *
     * @return array{primary: string, fallback: string|null}
     */
    public static function identifiersFor(string $folderName, string $filename): array
    {
        $fromFolder   = self::normalizeIdentifier($folderName);
        $fromFilename = self::normalizeIdentifier(pathinfo($filename, PATHINFO_FILENAME));

        if ($fromFolder === '') {
            return ['primary' => $fromFilename, 'fallback' => null];
        }

        $worthTrying = $fromFilename !== ''
            && $fromFilename !== $fromFolder
            && strlen($fromFilename) >= self::MIN_FALLBACK_LENGTH;

        return [
            'primary'  => $fromFolder,
            'fallback' => $worthTrying ? $fromFilename : null,
        ];
    }

    /**
     * Only the part before the first "_", "-", or "." is the real SKU/barcode —
     * everything after is a suffix OneDrive folders/filenames sometimes carry
     * (e.g. "_var1", "-var2", ".jpg"), so "0000066897644_var1" and
     * "0000066897644_var2" both resolve to the same identifier for matching.
     */
    private static function normalizeIdentifier(string $raw): string
    {
        $name = trim($raw);
        $cut  = strcspn($name, '_-.');

        return $cut > 0 ? substr($name, 0, $cut) : $name;
    }

    public function failed(\Throwable $e): void
    {
        UploadSession::where('id', $this->sessionId)->update([
            'scan_status'  => 'failed',
            'status'       => 'failed',
            'error_message' => 'Scan failed: ' . $e->getMessage(),
        ]);
    }
}
