<?php

namespace App\Services;

use App\Models\ProductSalesDaily;
use App\Models\Store;
use App\Models\StoreProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Copies one website's catalogue and its daily product sales out of Shopify,
 * for the Product Performance page to rank against.
 *
 * Read once a night rather than live: a year of orders with their line items is
 * minutes of work per store, and the page has to answer in a second. Nothing
 * here writes to Shopify.
 */
class ProductSalesSyncService
{
    /** How far back the first sync of a store reads. */
    public const BACKFILL_DAYS = 365;

    /**
     * How far back every later sync re-reads. Days are rewritten whole, so an
     * order cancelled or marked as a test after the night it was first counted
     * drops out on the next run inside this window.
     */
    public const REFRESH_DAYS = 14;

    /** Where the bulk export files land while they are read. Removed after every run. */
    public const WORK_DIR = 'product-sales';

    /** @var callable(Store): ShopifyService */
    private $clientFactory;

    public function __construct(?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? fn (Store $store) => new ShopifyService($store);
    }

    /**
     * @return array{products:int, sales_rows:int, from:string}
     */
    public function sync(Store $store, bool $full = false): array
    {
        $shopify  = ($this->clientFactory)($store);
        $settings = $shopify->getShopSettings();
        $timezone = $settings['timezone'];

        $backfill = $full || !$store->sales_synced_at;
        $from     = Carbon::now($timezone)
            ->subDays($backfill ? self::BACKFILL_DAYS : self::REFRESH_DAYS)
            ->startOfDay();

        $dir = storage_path('app/' . self::WORK_DIR);
        $tag = $store->id . '-' . uniqid();
        $productsFile = "{$dir}/{$tag}-products.jsonl";
        $ordersFile   = "{$dir}/{$tag}-orders.jsonl";

        try {
            $products = $shopify->exportProductsForPerformance($productsFile)
                ? $this->storeCatalogue($store, $productsFile)
                : $this->storeCatalogue($store, null);

            [$rows, $earliest] = $shopify->exportOrdersForPerformance($from, $ordersFile)
                ? $this->storeSales($store, $ordersFile, $from, $timezone)
                : $this->storeSales($store, null, $from, $timezone);
        } finally {
            @unlink($productsFile);
            @unlink($ordersFile);
        }

        // Coverage only ever widens. On a backfill it is where the data really
        // starts — later than asked for when the app lacks read_all_orders and
        // Shopify quietly returned 60 days instead of 365.
        $coveredFrom = $backfill
            ? ($earliest ?? $from->toDateString())
            : ($store->sales_covered_from ?? $from->toDateString());

        $store->forceFill([
            'sales_synced_at'    => now(),
            'sales_covered_from' => $coveredFrom,
            'sales_currency'     => $settings['currency'],
            'sales_sync_error'   => null,
        ])->save();

        return ['products' => $products, 'sales_rows' => $rows, 'from' => $from->toDateString()];
    }

    /** Replaces the store's catalogue copy with the export. Returns products stored. */
    private function storeCatalogue(Store $store, ?string $file): int
    {
        $products = [];
        $firstSku = [];

        foreach ($this->lines($file) as $line) {
            $id = (string) ($line['id'] ?? '');

            if (str_contains($id, '/Product/')) {
                $products[$this->numericId($id)] = $line;
            } elseif (isset($line['__parentId']) && array_key_exists('sku', $line)) {
                $parent = $this->numericId((string) $line['__parentId']);
                $sku    = trim((string) $line['sku']);

                if ($sku !== '' && !isset($firstSku[$parent])) {
                    $firstSku[$parent] = $sku;
                }
            }
        }

        $now  = now();
        $rows = [];

        foreach ($products as $productId => $p) {
            $rows[] = [
                'store_id'           => $store->id,
                'product_id'         => (string) $productId,
                'title'              => mb_substr((string) ($p['title'] ?? ''), 0, 512),
                'handle'             => $p['handle'] ?? null,
                'vendor'             => trim((string) ($p['vendor'] ?? '')) ?: null,
                'product_type'       => trim((string) ($p['productType'] ?? '')) ?: null,
                'status'             => strtolower((string) ($p['status'] ?? 'active')),
                'total_inventory'    => isset($p['totalInventory']) ? (int) $p['totalInventory'] : null,
                'sku'                => $firstSku[$productId] ?? null,
                'image_url'          => $p['featuredImage']['url'] ?? null,
                'shopify_created_at' => isset($p['createdAt']) ? Carbon::parse($p['createdAt']) : null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        DB::transaction(function () use ($store, $rows) {
            // Rebuilt whole rather than diffed: the export is the full
            // catalogue, so anything not in it has been deleted in Shopify.
            StoreProduct::where('store_id', $store->id)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                StoreProduct::insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * Rewrites the store's daily sales from $from onward. Returns the number of
     * rows written and the earliest day an order was found on.
     *
     * @return array{0:int, 1:?string}
     */
    private function storeSales(Store $store, ?string $file, Carbon $from, string $timezone): array
    {
        // Two passes, because a bulk export does not promise a line item comes
        // after the order it belongs to. The first learns which orders count
        // and on which day; the second adds up their line items.
        $orderDay = [];
        $earliest = null;

        foreach ($this->lines($file) as $line) {
            $id = (string) ($line['id'] ?? '');

            if (!str_contains($id, '/Order/')) {
                continue;
            }

            if (!empty($line['cancelledAt']) || !empty($line['test'])) {
                $orderDay[$id] = null;
                continue;
            }

            $day = Carbon::parse($line['createdAt'])->setTimezone($timezone)->toDateString();
            $orderDay[$id] = $day;

            if ($earliest === null || $day < $earliest) {
                $earliest = $day;
            }
        }

        // Flat arrays keyed "date|product": a year of a busy store is a few
        // hundred thousand keys, and nested arrays would cost several times
        // the memory for the same numbers.
        $units   = [];
        $revenue = [];

        foreach ($this->lines($file) as $line) {
            $parent = $line['__parentId'] ?? null;

            if ($parent === null || !array_key_exists('quantity', $line)) {
                continue;
            }

            $day = $orderDay[$parent] ?? null;

            // A line whose product has since been deleted has nothing to show
            // it against, and a custom line item never had a product at all.
            $productGid = $line['product']['id'] ?? null;

            if ($day === null || !$productGid) {
                continue;
            }

            $key = $day . '|' . $this->numericId((string) $productGid);
            $units[$key]   = ($units[$key] ?? 0) + (int) $line['quantity'];
            $revenue[$key] = ($revenue[$key] ?? 0.0) + (float) ($line['discountedTotalSet']['shopMoney']['amount'] ?? 0);
        }

        $rows = [];

        foreach ($units as $key => $count) {
            [$day, $productId] = explode('|', $key, 2);

            $rows[] = [
                'store_id'   => $store->id,
                'product_id' => $productId,
                'date'       => $day,
                'units'      => max(0, $count),
                'revenue'    => round($revenue[$key], 2),
            ];
        }

        DB::transaction(function () use ($store, $from, $rows) {
            ProductSalesDaily::where('store_id', $store->id)
                ->where('date', '>=', $from->toDateString())
                ->delete();

            foreach (array_chunk($rows, 1000) as $chunk) {
                ProductSalesDaily::insert($chunk);
            }
        });

        Log::info('Product sales synced', ['store' => $store->id, 'rows' => count($rows), 'from' => $from->toDateString()]);

        return [count($rows), $earliest];
    }

    /** Streams a JSONL file one decoded line at a time; nothing for a missing file. */
    private function lines(?string $file): \Generator
    {
        if ($file === null || !is_file($file)) {
            return;
        }

        $handle = fopen($file, 'r');

        try {
            while (($raw = fgets($handle)) !== false) {
                $decoded = json_decode($raw, true);

                if (is_array($decoded)) {
                    yield $decoded;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** "gid://shopify/Product/123" -> "123". */
    private function numericId(string $gid): string
    {
        return str_contains($gid, '/') ? substr($gid, strrpos($gid, '/') + 1) : $gid;
    }

    /**
     * Export files left behind by a run that died between download and
     * cleanup — a killed worker skips the finally block. Returns files removed.
     */
    public static function sweepWorkFiles(): int
    {
        $removed = 0;
        $cutoff  = now()->subDay()->timestamp;

        foreach (glob(storage_path('app/' . self::WORK_DIR . '/*')) ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }
}
