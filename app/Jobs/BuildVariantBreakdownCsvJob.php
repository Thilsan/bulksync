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
 * check asks one batched question per fifty SKUs; this asks one per product, so
 * on a ten thousand SKU list it is minutes of work and thousands of calls — a
 * cost nobody should pay by default for an answer they may not want.
 *
 * SKUs are grouped by their product before any of it happens. A pasted list
 * routinely holds several sizes of one style — GAT207LUG00323 and
 * GAT207LUG00325 are both AMERICAN TOURISTER TRAILON — and writing a full copy
 * of the product's variants under each would repeat six rows as twelve and pay
 * for the same lookup twice. The product is written once instead, with every
 * pasted SKU that reached it named in the first column.
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
            'variant_export_total'   => $this->countWorkUnits($sourcePath),
            'variant_export_scanned' => 0,
            'variant_export_failed'  => 0,
            'variant_export_error'   => null,
        ]);

        try {
            $out = fopen(storage_path("app/sku-checks/{$this->sessionId}-variants.csv"), 'w');
            fputcsv($out, [
                'SKU Checked', 'Status', 'Product ID', 'Product Name', 'Published',
                'Colour', 'Size', 'Variant SKU', 'Variant ID', 'Stock',
                'Has Image', 'Image Count',
                'Sizes In Colour', 'Sizes With Image', 'Colour Stock', 'Gallery Images',
            ]);

            $grouped = $this->groupSkusByProduct($sourcePath);

            $scanned = 0;
            $failed  = 0;
            $written = [];

            $in = fopen($sourcePath, 'r');
            fgetcsv($in); // header

            while (($row = fgetcsv($in)) !== false) {
                $sku = $row[0] ?? '';

                if (strtolower($row[1] ?? '') !== 'available') {
                    // Kept in the file rather than dropped: a SKU missing from
                    // the export would read as "no colours", not "not in Shopify".
                    fputcsv($out, [$sku, 'Not Available', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);
                    $scanned++;
                    continue;
                }

                $key = $this->productKey($row);

                // Already written, under a SKU earlier in the list whose row
                // names this one too.
                if (isset($written[$key])) {
                    continue;
                }

                $written[$key] = true;

                // Every pasted SKU that landed on this product, so a search for
                // any one of them finds these rows.
                $checked = implode(', ', $grouped[$key] ?? [$sku]);

                try {
                    $breakdown = $shopify->getSkuVariantBreakdown($sku, true);
                } catch (\Throwable $e) {
                    // One product's failure must not silently become "no colours",
                    // and must not throw away the thousands already written.
                    Log::warning("Variant export: lookup failed for {$sku}: " . $e->getMessage());
                    fputcsv($out, [$checked, 'Lookup Failed', $row[2] ?? '', $row[3] ?? '', '', '', '', '', '', '', '', '', '', '', '', '']);
                    $failed++;
                    $scanned++;
                    continue;
                }

                if ($breakdown === null) {
                    fputcsv($out, [$checked, 'No Variants Found', $row[2] ?? '', $row[3] ?? '', '', '', '', '', '', '', '', '', '', '', '', '']);
                    $scanned++;
                    continue;
                }

                foreach ($breakdown['colours'] as $colour) {
                    foreach ($colour['sizes'] as $size) {
                        fputcsv($out, [
                            $checked,
                            'Available',
                            $breakdown['product_id'],
                            $breakdown['product_title'],
                            $breakdown['published'] ? 'TRUE' : 'FALSE',
                            $colour['colour'],
                            $size['size'],
                            $size['sku'],
                            $size['variant_id'],
                            // Blank, not 0, when the store would not report it:
                            // a zero here reads as "out of stock", which is a
                            // different and possibly wrong answer.
                            $size['stock'] ?? '',
                            $size['has_image'] ? 'YES' : 'NO',
                            $size['image_count'],
                            $colour['variant_count'],
                            $colour['with_image_count'],
                            $colour['stock'] ?? '',
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

            Log::info("Variant export for session {$this->sessionId}: {$scanned} lookups, {$failed} failed.");

        } catch (\Throwable $e) {
            Log::error("BuildVariantBreakdownCsvJob({$this->sessionId}) failed: " . $e->getMessage());

            $session->update([
                'variant_export_status' => 'failed',
                'variant_export_error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Every checked SKU that belongs to each product, in the order they were
     * pasted. The check's own CSV already records the product each SKU resolved
     * to, so the grouping is known before the export asks its first question.
     *
     * @return array<string, list<string>>
     */
    private function groupSkusByProduct(string $path): array
    {
        $handle = fopen($path, 'r');
        fgetcsv($handle); // header

        $grouped = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (strtolower($row[1] ?? '') === 'available') {
                $grouped[$this->productKey($row)][] = $row[0] ?? '';
            }
        }

        fclose($handle);

        return $grouped;
    }

    /**
     * A check row's product, or the SKU itself when the check recorded no
     * product id — grouping on an empty key would merge unrelated SKUs into one
     * block, which is worse than repeating them.
     *
     * @param  list<string>  $row
     */
    private function productKey(array $row): string
    {
        $productId = trim($row[2] ?? '');

        return $productId !== '' ? "product:{$productId}" : 'sku:' . ($row[0] ?? '');
    }

    /**
     * What the progress bar counts: one lookup per distinct product, plus the
     * not-available rows copied straight across. Counting pasted SKUs instead
     * would show a bar that stalls and then jumps, because a list holding
     * twelve sizes of one style is a single lookup.
     */
    private function countWorkUnits(string $path): int
    {
        $handle = fopen($path, 'r');
        fgetcsv($handle); // header

        $products = [];
        $others   = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (strtolower($row[1] ?? '') === 'available') {
                $products[$this->productKey($row)] = true;
            } else {
                $others++;
            }
        }

        fclose($handle);

        return count($products) + $others;
    }

    public function failed(\Throwable $e): void
    {
        SkuCheckSession::where('id', $this->sessionId)->update([
            'variant_export_status' => 'failed',
            'variant_export_error'  => $e->getMessage(),
        ]);
    }
}
