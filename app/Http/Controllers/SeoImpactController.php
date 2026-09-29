<?php

namespace App\Http\Controllers;

use App\Models\SeoContentPush;
use App\Models\Store;
use Illuminate\Http\Request;

/**
 * What happened to organic traffic after the AI Content Generator rewrote a
 * product's search fields.
 *
 * Scoped to the website being worked in rather than to the person who pressed
 * push: the question is whether the store's content programme is working, and
 * one person's half of it is not an answer.
 */
class SeoImpactController extends Controller
{
    public function index(Request $request)
    {
        $store  = Store::getActive();
        $filter = (string) $request->get('filter', 'measured');

        $query = SeoContentPush::query()
            ->when($store, fn ($q) => $q->where('store_id', $store->id))
            ->with('user');

        if ($filter === 'measured') {
            $query->where('measurement_status', 'measured');
        } elseif ($filter === 'pending') {
            $query->where('measurement_status', 'pending');
        }

        $pushes = $query->orderByDesc('pushed_at')->paginate(50)->withQueryString();

        return view('seo-audit.impact', [
            'pushes'  => $pushes,
            'filter'  => $filter,
            'store'   => $store,
            'summary' => $this->summary($store),
        ]);
    }

    /**
     * The one line worth reading: across every measured push, what organic
     * search did.
     *
     * Totals rather than an average of per-product percentages — a product that
     * went from 1 session to 4 is a 300% rise that says nothing about a
     * catalogue, and averaging those would let the quietest pages shout down
     * the busiest ones.
     */
    private function summary(?Store $store): array
    {
        $measured = SeoContentPush::query()
            ->when($store, fn ($q) => $q->where('store_id', $store->id))
            ->where('measurement_status', 'measured');

        $count  = (clone $measured)->count();
        $before = (int) (clone $measured)->sum('sessions_before');
        $after  = (int) (clone $measured)->sum('sessions_after');

        $pending = SeoContentPush::query()
            ->when($store, fn ($q) => $q->where('store_id', $store->id))
            ->where('measurement_status', 'pending')
            ->count();

        // Only pushes Search Console could answer for. Kept apart from the
        // session totals because a store may have one source and not the other,
        // and averaging across a mixture would quietly compare different sets.
        $withSearch = (clone $measured)->whereNotNull('impressions_before');

        $searchCount = (clone $withSearch)->count();
        $impsBefore  = (int) (clone $withSearch)->sum('impressions_before');
        $impsAfter   = (int) (clone $withSearch)->sum('impressions_after');
        $clicksBefore = (int) (clone $withSearch)->sum('clicks_before');
        $clicksAfter  = (int) (clone $withSearch)->sum('clicks_after');

        return [
            'search_measured'    => $searchCount,
            'impressions_before' => $impsBefore,
            'impressions_after'  => $impsAfter,
            'clicks_before'      => $clicksBefore,
            'clicks_after'       => $clicksAfter,
            // Computed from the totals, not averaged from per-page rates: a
            // page shown twice must not weigh as much as one shown ten
            // thousand times.
            'ctr_before'         => $impsBefore > 0 ? round($clicksBefore / $impsBefore * 100, 2) : null,
            'ctr_after'          => $impsAfter > 0 ? round($clicksAfter / $impsAfter * 100, 2) : null,
            'measured'        => $count,
            'pending'         => $pending,
            'sessions_before' => $before,
            'sessions_after'  => $after,
            'change_percent'  => $before > 0 ? round((($after - $before) / $before) * 100, 1) : null,
            'improved'        => (clone $measured)->whereColumn('sessions_after', '>', 'sessions_before')->count(),
            'declined'        => (clone $measured)->whereColumn('sessions_after', '<', 'sessions_before')->count(),
            'window_days'     => SeoContentPush::WINDOW_DAYS,
            'settle_days'     => SeoContentPush::SETTLE_DAYS,
        ];
    }
}
