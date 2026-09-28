<?php

namespace App\Jobs;

use App\Models\BarcodeImageItem;
use App\Models\BarcodeImageSession;
use App\Services\ProductImageScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Works down a list of barcodes, one at a time, filling a folder per barcode.
 *
 * Deliberately serial: this is somebody else's public website being read, and
 * a run of two thousand barcodes fanned out across workers is indistinguishable
 * from an attack. One barcode at a time with a short pause is slower and stays
 * welcome.
 *
 * A barcode that cannot be found, or whose images will not download, is
 * recorded as such and the run carries on. Only something that breaks the run
 * itself — the site refusing everything, the disk filling — fails the session.
 */
class RunBarcodeImageDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10800;
    public int $tries   = 1;

    /** Microseconds between barcodes — courtesy to the site being read. */
    private const PAUSE_MICROSECONDS = 300_000;

    public function __construct(public readonly int $sessionId) {}

    public function handle(ProductImageScraper $scraper): void
    {
        $session = BarcodeImageSession::findOrFail($this->sessionId);

        $barcodes = array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[\r\n,;\t]+/', (string) $session->raw_barcodes) ?: [])
        )));

        $site = $scraper->normaliseSite($session->site_url);

        if (!$site || $barcodes === []) {
            $session->update([
                'status'        => 'failed',
                'error_message' => $site ? 'No barcodes to look up.' : 'That website address could not be read.',
                'raw_barcodes'  => null,
            ]);

            return;
        }

        $session->update([
            'status'         => 'running',
            'site_url'       => $site,
            'total_barcodes' => count($barcodes),
        ]);

        Log::info("RunBarcodeImageDownloadJob: {$session->total_barcodes} barcodes against {$site} for session {$this->sessionId}");

        $processed  = 0;
        $found      = 0;
        $missing    = 0;
        $downloaded = 0;

        try {
            foreach ($barcodes as $barcode) {
                $item = BarcodeImageItem::create([
                    'barcode_image_session_id' => $session->id,
                    'barcode'                  => $barcode,
                    'status'                   => 'pending',
                ]);

                try {
                    $result = $scraper->forBarcode($site, $barcode);
                } catch (\Throwable $e) {
                    Log::warning("Barcode {$barcode} failed on {$site}: " . $e->getMessage());

                    $item->update(['status' => 'failed', 'message' => $e->getMessage()]);
                    $processed++;
                    $missing++;
                    $session->update(['processed' => $processed, 'missing_count' => $missing]);

                    continue;
                }

                if (!$result['url']) {
                    $item->update([
                        'status'  => 'not_found',
                        'message' => 'No product on that site matched this barcode.',
                    ]);

                    $processed++;
                    $missing++;
                    $session->update(['processed' => $processed, 'missing_count' => $missing]);

                    continue;
                }

                // The folder is named after the barcode, whether or not any
                // image lands in it — an empty folder is itself the answer to
                // "did we get pictures for this one".
                $folder = $session->folderFor($barcode);
                $stem   = BarcodeImageSession::safeFolder($barcode);
                $saved  = 0;

                foreach ($result['images'] as $index => $imageUrl) {
                    try {
                        if ($scraper->download($imageUrl, $folder, $stem, $index + 1)) {
                            $saved++;
                        }
                    } catch (\Throwable $e) {
                        Log::debug("Image {$imageUrl} for {$barcode} failed: " . $e->getMessage());
                    }
                }

                $item->update([
                    'status'        => $saved > 0 ? 'found' : 'not_found',
                    'product_url'   => $result['url'],
                    'product_title' => $result['title'],
                    'image_count'   => $saved,
                    'message'       => $saved > 0
                        ? (($result['matches'] ?? 1) > 1
                            ? $result['matches'] . ' product pages matched this barcode — all of their images are in the folder.'
                            : null)
                        : 'The product page was found, but none of its images could be downloaded.',
                ]);

                $processed++;
                $downloaded += $saved;
                $saved > 0 ? $found++ : $missing++;

                $session->update([
                    'processed'         => $processed,
                    'found_count'       => $found,
                    'missing_count'     => $missing,
                    'images_downloaded' => $downloaded,
                ]);

                usleep(self::PAUSE_MICROSECONDS);
            }

            $session->update([
                'status'       => 'completed',
                'raw_barcodes' => null,
            ]);

            Log::info("RunBarcodeImageDownloadJob: done — {$found} with images, {$missing} without, {$downloaded} files.");

        } catch (\Throwable $e) {
            Log::error('RunBarcodeImageDownloadJob failed: ' . $e->getMessage());

            $session->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'raw_barcodes'  => null,
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        BarcodeImageSession::where('id', $this->sessionId)->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
