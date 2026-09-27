<?php

namespace App\Jobs;

use App\Models\SkuCheckSession;
use App\Models\Store;
use App\Services\ShopifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The colour/size breakdown of a finished check, written as a file.
 *
 * The on-page drill-down asks Shopify for one row at a time, which is right for
 * a handful of rows and wrong for a whole list. This is the other shape of the
 * same question: one lookup per mapped SKU, written as one row per variant, so
 * a buyer can sort or pivot on "which colours have no picture" across thousands
 * of SKUs at once.
 *
 * It is deliberately a separate, opt-in run rather than part of the check. A
 * check asks one batched question per fifty SKUs; this asks one per SKU, so on
 * a ten thousand SKU list it is minutes of work and ten thousand calls — a cost
 * nobody should pay by default for an answer they may not want.
 */
class BuildVariantBreakdownCsvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10800;
    public int $tries   = 1;

    /** SKUs per progress update — how often the page's counter moves. */
    private const PROGRESS_CHUNK = 10;

    public function __construct(public readonly int $sessionId) {}

    public function handle(): void
    {
        $session = SkuCheckSession::findOrFail($this->sessionId);

        $sourcePath = storage_path("app/sku-checks/{$this->sessionId}.csv");

        if (!file_exists($sourcePath)) {
            $session->update([
                'variant_export_status' => 'failed',
                'variant_export_error'  => 'The check result file is no longer on disk.',
            ]);

            return;
        }

        $store = $session->store_id
            ? Store::find($session->store_id)
            : Store::getActive($session->user_id);

        $shopify = app(ShopifyService::class, ['store' => $store]);

        $session->update([
            'variant_export_status'  => 'running',
            'variant_export_total'   => $this->countRows($sourcePath),
            'variant_export_scanned' => 0,
            'variant_export_failed'  => 0,
            'variant_export_error'   => null,
        ]);

        try {
            $out = fopen(storage_path("app/sku-checks/{$this->sessionId}-variants.csv"), 'w');
            fputcsv($out, [
                'SKU Checked', 'Status', 'Product ID', 'Product Name', 'Published',
                'Colour', 'Size', 'Variant SKU', 'Variant ID',
                'Has Image', 'Image Count',
                'Sizes In Colour', 'Sizes With Image', 'Gallery Images',
            ]);

            $in = fopen($sourcePath, 'r');
            fgetcsv($in); // header

            $scanned = 0;
            $failed  = 0;

            while (($row = fgetcsv($in)) !== false) {
                $sku = $row[0] ?? '';

                if (strtolower($row[1] ?? '') !== 'available') {
                    // Kept in the file rather than dropped: a SKU missing from
                    // the export would read as "no colours", not "not in Shopify".
                    fputcsv($out, [$sku, 'Not Available', '', '', '', '', '', '', '', '', '', '', '', '']);
                    $scanned++;
                    continue;
                }

                try {
                    $breakdown = $shopify->getSkuVariantBreakdown($sku, true);
                } catch (\Throwable $e) {
                    // One SKU's failure must not silently become "no colours",
                    // and must not throw away the thousands already written.
                    Log::warning("Variant export: lookup failed for {$sku}: " . $e->getMessage());
                    fputcsv($out, [$sku, 'Lookup Failed', $row[2] ?? '', $row[3] ?? '', '', '', '', '', '', '', '', '', '', '']);
                    $failed++;
                    $scanned++;
                    continue;
                }

                if ($breakdown === null) {
                    fputcsv($out, [$sku, 'No Variants Found', $row[2] ?? '', $row[3] ?? '', '', '', '', '', '', '', '', '', '', '']);
                    $scanned++;
                    continue;
                }

                foreach ($breakdown['colours'] as $colour) {
                    foreach ($colour['sizes'] as $size) {
                        fputcsv($out, [
                            $sku,
                            'Available',
                            $breakdown['product_id'],
                            $breakdown['product_title'],
                            $breakdown['published'] ? 'TRUE' : 'FALSE',
                            $colour['colour'],
                            $size['size'],
                            $size['sku'],
                            $size['variant_id'],
                            $size['has_image'] ? 'YES' : 'NO',
                            $size['image_count'],
                            $colour['variant_count'],
                            $colour['with_image_count'],
                            $breakdown['gallery_count'],
                        ]);
                    }
                }

                $scanned++;

                if ($scanned % self::PROGRESS_CHUNK === 0) {
                    $session->update([
                        'variant_export_scanned' => $scanned,
                        'variant_export_failed'  => $failed,
                    ]);
                }
            }

            fclose($in);
            fclose($out);

            $session->update([
                'variant_export_status'  => 'completed',
                'variant_export_scanned' => $scanned,
                'variant_export_failed'  => $failed,
            ]);

            Log::info("Variant export for session {$this->sessionId}: {$scanned} SKUs, {$failed} failed lookups.");

        } catch (\Throwable $e) {
            Log::error("BuildVariantBreakdownCsvJob({$this->sessionId}) failed: " . $e->getMessage());

            $session->update([
                'variant_export_status' => 'failed',
                'variant_export_error'  => $e->getMessage(),
            ]);
        }
    }

    private function countRows(string $path): int
    {
        $handle = fopen($path, 'r');
        fgetcsv($handle); // header

        $rows = 0;
        while (fgetcsv($handle) !== false) {
            $rows++;
        }
        fclose($handle);

        return $rows;
    }

    public function failed(\Throwable $e): void
    {
        SkuCheckSession::where('id', $this->sessionId)->update([
            'variant_export_status' => 'failed',
            'variant_export_error'  => $e->getMessage(),
        ]);
    }
}
