<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One analytics row per store for the management dashboard's Analytics tab.
 *
 * Each store's numbers come straight from its own Shopify — never the
 * pre-aggregated ecommerce-server endpoint the Orders tab uses — so a store
 * with no credentials or a failing token is reported as such rather than
 * dropped from the table or left to fail the whole page.
 */
class ShopifyAnalyticsService
{
    private const CACHE_TTL_MINUTES = 15;

    /**
     * Builds the per-store Shopify client. Takes a plain callable rather than
     * a ShopifyService type hint so tests can swap in a stub that never
     * touches the network.
     *
     * @var callable(Store): ShopifyService
     */
    private $clientFactory;

    public function __construct(?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? fn (Store $store) => new ShopifyService($store);
    }

    /** @param  Collection<int, Store>  $stores */
    public function forStores(Collection $stores, Carbon $from, Carbon $to): array
    {
        return $stores->map(fn (Store $store) => $this->forStore($store, $from, $to))->all();
    }

    private function forStore(Store $store, Carbon $from, Carbon $to): array
    {
        $base = ['store' => $store->name, 'store_id' => $store->id];

        if (!$store->shopify_domain || !$store->shopify_access_token) {
            return $base + ['status' => 'not_connected'];
        }

        $key = sprintf(
            'shopify_analytics.%d.%s.%s',
            $store->id,
            $from->toDateString(),
            $to->toDateString(),
        );

        try {
            $data = Cache::remember($key, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($store, $from, $to) {
                return ($this->clientFactory)($store)->getOrderAnalytics($from, $to);
            });
        } catch (\Throwable $e) {
            Log::warning("Shopify analytics failed for store {$store->id}: " . $e->getMessage());

            // Shopify's own wording for a token that was never granted the
            // scope this call needs — not a transient failure, so it gets its
            // own label rather than sitting under the same "Unavailable" as
            // a network hiccup, one nobody would think to reload their way
            // out of.
            if (stripos($e->getMessage(), 'access denied') !== false) {
                return $base + [
                    'status'  => 'missing_scope',
                    'message' => "This store's Shopify app needs the read_orders permission enabled.",
                ];
            }

            return $base + ['status' => 'unavailable'];
        }

        return $base + $data + [
            'status'               => 'ok',
            'average_order_value'  => $data['orders'] > 0 ? $data['revenue'] / $data['orders'] : 0.0,
        ];
    }
}
