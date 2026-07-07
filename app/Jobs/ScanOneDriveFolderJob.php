<?php

namespace App\Jobs;

use App\Models\UploadItem;
use App\Models\UploadSession;
use App\Services\OneDriveService;
use App\Services\ShopifyService;
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

        $store   = $session->store_id ? \App\Models\Store::find($session->store_id) : \App\Models\Store::getActive($session->user_id);
        $shopify = new ShopifyService($store);

        try {
            $totalScanned = 0;
            $buffer       = [];

            // Stream through OneDrive pages one at a time — never load all 30k into memory
            $oneDrive->streamFolderImages(
                $session->onedrive_link,
                function (array $file) use ($session, &$buffer, &$totalScanned) {
                    // Use folder name as SKU if images are organised in item-code folders,
                    // otherwise fall back to the filename (without extension)
                    $identifier = !empty($file['folder_name'])
                        ? $file['folder_name']
                        : pathinfo($file['filename'], PATHINFO_FILENAME);

                    $sku = $this->normalizeIdentifier($identifier);

                    $buffer[] = [
                        'upload_session_id'    => $session->id,
                        'filename'             => $file['filename'],
                        'sku_detected'         => $sku,
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

            Log::info("ScanOneDriveFolderJob: scan complete — {$totalScanned} files. Warming SKU cache…");

            // Only warm SKU cache for small stores — large stores (150k+ products)
            // take too long to cache and will timeout. Use live GraphQL lookup instead.
            try {
                $productCount = $shopify->getProductCount();
                if ($productCount < 10000) {
                    if ($shopify->isSkuCacheWarmed()) {
                        Log::info("ScanOneDriveFolderJob: SKU cache already warm — skipping re-warm.");
                    } else {
                        $count = $shopify->warmSkuCache();
                        Log::info("ScanOneDriveFolderJob: SKU cache ready — {$count} SKUs mapped.");
                    }
                } else {
                    Log::info("ScanOneDriveFolderJob: large store ({$productCount} products) — skipping cache warm, using live GraphQL lookup.");
                }
            } catch (\Throwable $e) {
                Log::warning("ScanOneDriveFolderJob: SKU cache warm failed (jobs will use live lookup): " . $e->getMessage());
            }

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
     * Strip trailing markers OneDrive folders/filenames sometimes carry
     * (e.g. "_var1", "-var2", "_jpg") so multiple photos of the same item
     * — "0000066897644_var1", "0000066897644_var2" — resolve to the same
     * SKU/barcode instead of being treated as separate, unmatched items.
     */
    private function normalizeIdentifier(string $raw): string
    {
        $name = trim($raw);

        do {
            $stripped = preg_replace(
                '/[\s_-]+(?:var(?:iant)?\.?\s*\d*|jpe?g|png|gif|webp|bmp|tiff?|avif)$/i',
                '',
                $name
            );
            $changed = $stripped !== $name;
            $name    = $stripped;
        } while ($changed && $name !== '');

        return $name !== '' ? $name : trim($raw);
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
