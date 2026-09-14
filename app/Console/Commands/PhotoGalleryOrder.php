<?php

namespace App\Console\Commands;

use App\Jobs\SettleGalleryOrderJob;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\Store;
use App\Services\ShopifyService;
use Illuminate\Console\Command;

/**
 * Show what order a run's photos are meant to be in, and what order Shopify has
 * them in — side by side.
 *
 *   php artisan photo:gallery-order 42
 *   php artisan photo:gallery-order 42 --fix
 *
 * Written because "still mixed" has three quite different causes and they look
 * identical from the outside: the wrong sequence was asked for, the right one
 * was asked for and Shopify ignored it, or nothing asked at all because the
 * workers were still running last week's code. This says which.
 *
 * Reads only, unless --fix is given.
 */
class PhotoGalleryOrder extends Command
{
    protected $signature = 'photo:gallery-order
                            {session : the photo edit session id}
                            {--fix : put Shopify in the order shown, rather than only reporting it}';

    protected $description = 'Compare a run\'s intended photo order with the order Shopify actually has';

    public function handle(): int
    {
        $session = PhotoEditSession::find((int) $this->argument('session'));

        if (!$session) {
            $this->error('No such session.');

            return self::FAILURE;
        }

        $store = $session->store_id ? Store::find($session->store_id) : Store::getActive($session->user_id);

        if (!$store) {
            $this->error('That run has no store to ask.');

            return self::FAILURE;
        }

        $shopify = new ShopifyService($store);

        $products = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->whereNotNull('product_id')
            ->whereNotNull('shopify_image_id')
            ->distinct()
            ->pluck('product_id');

        if ($products->isEmpty()) {
            $this->warn('Nothing in this run has reached Shopify yet.');

            return self::SUCCESS;
        }

        $wrong = 0;

        foreach ($products as $productId) {
            $wrong += $this->report($shopify, $session, (string) $productId) ? 0 : 1;
        }

        if ($wrong && !$this->option('fix')) {
            $this->newLine();
            $this->line("Run again with --fix to put {$wrong} gallery(s) into the order above.");
        }

        return self::SUCCESS;
    }

    /** True if this product's gallery already matches. */
    private function report(ShopifyService $shopify, PhotoEditSession $session, string $productId): bool
    {
        $this->newLine();
        $this->info("Product {$productId}");

        $desired = SettleGalleryOrderJob::desiredOrder($session->id, $productId);

        // Named by what a person can recognise. An image id says nothing about
        // which photograph it is, and the whole question here is which photo
        // ended up where.
        $names = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->where('product_id', $productId)
            ->whereNotNull('shopify_image_id')
            ->get()
            ->keyBy(fn ($i) => (string) $i->shopify_image_id)
            ->map(fn ($i) => "{$i->sku_detected}  {$i->filename}");

        $actual = collect($shopify->getProductImages($productId))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values();

        // Images this run did not upload are left out of the comparison: they
        // are not ours to have an opinion about.
        $ours = $actual->filter(fn ($id) => in_array($id, $desired, true))->values()->all();

        $rows = [];

        foreach ($desired as $i => $id) {
            $has = $ours[$i] ?? null;

            $rows[] = [
                $i + 1,
                $names[$id] ?? $id,
                $has === null ? '—' : ($names[$has] ?? $has),
                $has === $id ? 'ok' : 'MOVED',
            ];
        }

        $this->table(['#', 'should be', 'Shopify has', ''], $rows);

        if ($ours === $desired) {
            $this->line('  In order.');

            return true;
        }

        $this->warn('  Out of order.');

        if ($this->option('fix')) {
            foreach ($desired as $i => $id) {
                $shopify->setImagePosition($productId, $id, $i + 1);
            }

            $this->info('  Reordered.');
        }

        return false;
    }
}
