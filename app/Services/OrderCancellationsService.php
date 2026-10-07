<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Every cancelled order across the connected stores, with the reason it was
 * cancelled — what the Ecom Delivery tab's Cancelled tile counts but cannot
 * explain.
 *
 * The delivery endpoint only reports how many orders sit in each status.
 * The reason and the staff note were typed into Shopify's cancel dialog, so
 * they come from each store's own Shopify, the way the analytics tab does.
 */
class OrderCancellationsService
{
    private const CACHE_TTL_MINUTES = 15;

    /**
     * Shopify's cancel reasons, in the words the cancel dialog uses for them.
     * Anything Shopify adds later keeps a readable version of its own name.
     */
    public const REASONS = [
        'CUSTOMER'  => 'Customer changed or cancelled order',
        'DECLINED'  => 'Payment declined',
        'FRAUD'     => 'Fraudulent order',
        'INVENTORY' => 'Items unavailable',
        'STAFF'     => 'Staff error',
        'OTHER'     => 'Other',
    ];

    /**
     * Builds the per-store Shopify client. A plain callable so tests can swap
     * in a stub that never touches the network.
     *
     * @var callable(Store): ShopifyService
     */
    private $clientFactory;

    public function __construct(?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? fn (Store $store) => new ShopifyService($store);
    }

    /**
     * @param  Collection<int, Store>  $stores
     * @return array{orders: list<array>, failed: list<string>}
     */
    public function forStores(Collection $stores, Carbon $from, Carbon $to): array
    {
        $orders = [];
        $failed = [];

        foreach ($stores as $store) {
            // A store with no token has nothing to ask, and saying so here
            // would repeat what the analytics tab already says about it.
            if (!$store->shopify_domain || !$store->shopify_access_token) {
                continue;
            }

            $key = sprintf('order_cancellations.%d.%s.%s', $store->id, $from->toDateString(), $to->toDateString());

            try {
                $rows = Cache::remember($key, now()->addMinutes(self::CACHE_TTL_MINUTES),
                    fn () => ($this->clientFactory)($store)->getCancelledOrders($from, $to));
            } catch (\Throwable $e) {
                Log::warning("Cancelled orders failed for store {$store->id}: " . $e->getMessage());
                $failed[] = $store->name;

                continue;
            }

            foreach ($rows as $row) {
                $orders[] = $row + [
                    'store'        => $store->name,
                    'reason_label' => self::reason($row['reason'] ?? null),
                ];
            }
        }

        usort($orders, fn ($a, $b) => strcmp($b['cancelled_at'], $a['cancelled_at']));

        return ['orders' => $orders, 'failed' => $failed];
    }

    public static function reason(?string $code): string
    {
        if ($code === null || $code === '') {
            return 'Not given';
        }

        return self::REASONS[$code] ?? ucfirst(strtolower(str_replace('_', ' ', $code)));
    }
}
