<?php

namespace App\Support;

use App\Models\ProductRequest;
use App\Models\ProductRequestDraftProduct;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The other half of the orders screen: what is being brought to market.
 *
 * Orders answer what sold; this answers what is on the way — how many product
 * creation requests were raised, how many are still moving through the
 * workflow, and how many products actually reached a storefront. Two numbers
 * on one page beat two screens, because the second is what explains the first
 * three months later.
 *
 * Two clocks run here on purpose. Requested, published and pushed are counted
 * inside the chosen date range — they are events. Pending, on hold, overdue
 * and the stage breakdown are counted as they stand right now, whatever the
 * range says: a request raised in June and still stuck today is the thing an
 * operations team needs to see, and a date filter would hide it.
 */
class ProductCreationSummary
{
    public static function for(User $user, Carbon $from, Carbon $to): array
    {
        // Requests move between teams, so ownership is "may I open it", not
        // "did I raise it". Super admins and anyone with a workflow role see
        // the whole pipeline, which is the point of a management screen.
        $visible = fn () => ProductRequest::query()->visibleTo($user);

        $raised = $visible()->whereBetween('created_at', [$from, $to]);
        $open   = $visible()->whereNotIn('status', ProductRequest::CLOSED_STATUSES);

        return [
            'requested'      => (clone $raised)->count(),
            'skus_requested' => (int) (clone $raised)->sum('total_skus'),

            'open'     => (clone $open)->count(),
            'on_hold'  => $visible()->onHold()->count(),
            'overdue'  => (clone $open)
                ->whereNotNull('online_launch_date')
                ->where('online_launch_date', '<', now())
                ->count(),

            'published' => $visible()
                ->whereIn('status', [ProductRequest::PUBLISHED, ProductRequest::COMPLETED])
                ->whereBetween('published_at', [$from, $to])
                ->count(),
            'cancelled' => $visible()
                ->where('status', ProductRequest::CANCELLED)
                ->whereBetween('cancelled_at', [$from, $to])
                ->count(),

            'stages'   => self::stages($open),
            'mapping'  => self::mapping($open),
            'uploads'  => self::uploads($user, $from, $to),
            'awaiting' => self::awaiting($visible),
        ];
    }

    /**
     * Where the open requests are sitting, in workflow order.
     *
     * Retired stages are not filtered out: requests raised under the old flow
     * still hold them, and a request parked on one has to appear somewhere or
     * the counts stop adding up to the pipeline total.
     */
    private static function stages($open): array
    {
        $counts = (clone $open)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $ordered = [];

        foreach (ProductRequest::PIPELINE as $stage) {
            if (($counts[$stage] ?? 0) > 0) {
                $ordered[] = [
                    'stage' => $stage,
                    'label' => ProductRequest::STATUS_LABELS[$stage] ?? $stage,
                    'count' => (int) $counts[$stage],
                ];
            }
        }

        return $ordered;
    }

    /**
     * SKU mapping across everything still open.
     *
     * A request cannot go anywhere until its SKUs exist in Cegid, so this is
     * usually the answer to "why is nothing moving". The roll-up columns are
     * kept on the request itself, so this never fans out per SKU.
     */
    private static function mapping($open): array
    {
        $row = (clone $open)
            ->selectRaw('COALESCE(SUM(total_skus),0) total, COALESCE(SUM(mapped_skus),0) mapped, COALESCE(SUM(pending_skus),0) pending, COALESCE(SUM(not_mapped_skus),0) missing')
            ->first();

        return [
            'total'   => (int) ($row->total ?? 0),
            'mapped'  => (int) ($row->mapped ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'missing' => (int) ($row->missing ?? 0),
        ];
    }

    /**
     * Products actually created in Shopify from these requests.
     *
     * This is the only number on the panel that means a product exists on a
     * storefront — everything else is a request about products. Pending drafts
     * are staged and reviewed but not pushed; failed ones were refused by
     * Shopify and are still waiting for somebody.
     */
    private static function uploads(User $user, Carbon $from, Carbon $to): array
    {
        $visibleIds = ProductRequest::query()->visibleTo($user)->select('id');

        $drafts = fn () => ProductRequestDraftProduct::query()->whereIn('product_request_id', $visibleIds);

        return [
            'pushed'  => $drafts()
                ->where('push_status', ProductRequestDraftProduct::PUSHED)
                ->whereBetween('pushed_at', [$from, $to])
                ->count(),
            'pending' => $drafts()->where('push_status', ProductRequestDraftProduct::PENDING)->count(),
            'failed'  => $drafts()->where('push_status', ProductRequestDraftProduct::FAILED)->count(),
        ];
    }

    /** The two stages where a request is waiting on somebody outside the team. */
    private static function awaiting(callable $visible): array
    {
        return [
            'mapping' => $visible()->where('status', ProductRequest::WAITING_MAPPING)->count(),
            'images'  => $visible()->whereIn('status', [
                ProductRequest::WAITING_IMAGES,
                ProductRequest::PHOTOSHOOT_SCHEDULED,
                ProductRequest::PHOTOSHOOT_COMPLETED,
            ])->count(),
        ];
    }
}
