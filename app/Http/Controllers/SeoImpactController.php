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

        return [
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
