<?php

namespace App\Jobs;

use App\Models\SeoContentPush;
use App\Models\Store;
use App\Services\SearchConsoleService;
use App\Services\SeoImpactService;
use App\Services\ShopifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reads back what organic search did to the products whose SEO content was
 * rewritten, once enough time has passed for Google to have recrawled them.
 *
 * Nothing here writes to Shopify. It exists so the question "did the AI content
 * actually do anything" has an answer other than an opinion.
 */
class MeasureSeoImpactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries   = 1;

    public function handle(): void
    {
        $due = SeoContentPush::readyToMeasure()->get();

        if ($due->isEmpty()) {
            return;
        }

        Log::info("MeasureSeoImpactJob: {$due->count()} pushes due for measurement");

        foreach ($due->groupBy('store_id') as $storeId => $pushes) {
            $store = Store::find($storeId);

            // Either source alone is worth a reading. Analytics says how many
            // people arrived; Search Console says how many were shown the page
            // and chose to click. With neither, there is nothing to say.
            if (!$store?->ga4_property_id && !$store?->gsc_site_url) {
                $this->giveUp($pushes, 'no_data', 'Neither a GA4 property nor a Search Console site is set for this website.');
                continue;
            }

            $this->resolveHandles($store, $pushes);

            // One GA4 call per push date, not per push: every product pushed on
            // the same day shares the same before and after windows, and a
            // session can easily cover hundreds of products.
            foreach ($pushes->groupBy(fn ($push) => $push->pushed_at->toDateString()) as $sameDay) {
                $this->measureDay($store, $sameDay);
            }
        }
    }

    /**
     * Fills in any handle not yet known. Products deleted since the push keep a
     * null handle and are closed off as unmeasurable rather than retried daily.
     */
    private function resolveHandles(Store $store, $pushes): void
    {
        $missing = $pushes->whereNull('handle');

        if ($missing->isEmpty()) {
            return;
        }

        $handles = $this->shopifyFor($store)->getProductHandles(
            $missing->pluck('product_id')->all()
        );

        foreach ($missing as $push) {
            if (isset($handles[$push->product_id])) {
                $push->update(['handle' => $handles[$push->product_id]]);
            }
        }
    }

    private function measureDay(Store $store, $pushes): void
    {
        $pushedAt = $pushes->first()->pushed_at;
        $window   = SeoContentPush::WINDOW_DAYS;

        // The day of the push belongs to neither window: it is half old content
        // and half new, and counting it would blur the very boundary being
        // measured.
        $beforeTo   = $pushedAt->copy()->subDay()->endOfDay();
        $beforeFrom = $beforeTo->copy()->subDays($window - 1)->startOfDay();
        $afterFrom  = $pushedAt->copy()->addDay()->startOfDay();
        $afterTo    = $afterFrom->copy()->addDays($window - 1)->endOfDay();

        // Search Console has no data for the last few days and answers with a
        // partial window rather than an error, which would read as a collapse
        // in traffic. A push whose after-window has not fully closed there is
        // left for tomorrow.
        if ($store->gsc_site_url && $afterTo->gt(SearchConsoleService::latestCompleteDay())) {
            return;
        }

        $sessions = ['before' => [], 'after' => []];
        $search   = ['before' => [], 'after' => []];

        try {
            if ($store->ga4_property_id) {
                $sessions = $this->impactService()->sessionsByHandle(
                    (string) $store->ga4_property_id,
                    $beforeFrom, $beforeTo, $afterFrom, $afterTo,
                );
            }

            if ($store->gsc_site_url) {
                $console = $this->searchConsoleService();
                $site    = (string) $store->gsc_site_url;

                $search = [
                    'before' => $console->performanceByHandle($site, $beforeFrom, $beforeTo),
                    'after'  => $console->performanceByHandle($site, $afterFrom, $afterTo),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('MeasureSeoImpactJob: read failed', [
                'store' => $store->id,
                'error' => $e->getMessage(),
            ]);

            // Left pending on purpose: a property that was briefly unreachable
            // should be asked again tomorrow, not written off.
            return;
        }

        foreach ($pushes as $push) {
            if (!$push->handle) {
                $push->update([
                    'measurement_status' => 'no_data',
                    'measurement_note'   => 'Product no longer exists in Shopify, so its URL cannot be matched.',
                    'measured_at'        => now(),
                ]);
                continue;
            }

            $handle = strtolower($push->handle);
            $before = $sessions['before'][$handle] ?? 0;
            $after  = $sessions['after'][$handle] ?? 0;

            $searchBefore = $search['before'][$handle] ?? null;
            $searchAfter  = $search['after'][$handle] ?? null;

            // A page nobody saw and nobody visited is reported as having no
            // data rather than as a flat zero-percent result, which would read
            // as "the rewrite changed nothing" when in truth nobody was ever
            // there to count. Being shown in the results counts as being seen,
            // even when no one clicked — that is a finding in itself.
            $seen = $before > 0 || $after > 0
                || ($searchBefore['impressions'] ?? 0) > 0
                || ($searchAfter['impressions'] ?? 0) > 0;

            $push->update([
                'sessions_before'    => $before,
                'sessions_after'     => $after,
                'impressions_before' => $searchBefore['impressions'] ?? null,
                'impressions_after'  => $searchAfter['impressions'] ?? null,
                'clicks_before'      => $searchBefore['clicks'] ?? null,
                'clicks_after'       => $searchAfter['clicks'] ?? null,
                'ctr_before'         => $searchBefore['ctr'] ?? null,
                'ctr_after'          => $searchAfter['ctr'] ?? null,
                'position_before'    => $searchBefore['position'] ?? null,
                'position_after'     => $searchAfter['position'] ?? null,
                'measured_at'        => now(),
                'measurement_status' => $seen ? 'measured' : 'no_data',
                'measurement_note'   => $seen
                    ? null
                    : 'This URL had no organic sessions and no search impressions in either window.',
            ]);
        }
    }

    private function giveUp($pushes, string $status, string $note): void
    {
        foreach ($pushes as $push) {
            $push->update([
                'measurement_status' => $status,
                'measurement_note'   => $note,
                'measured_at'        => now(),
            ]);
        }
    }

    /** Seams: overridden in tests so nothing reaches Shopify or Google. */
    protected function shopifyFor(Store $store): ShopifyService
    {
        return new ShopifyService($store);
    }

    protected function impactService(): SeoImpactService
    {
        return new SeoImpactService();
    }

    protected function searchConsoleService(): SearchConsoleService
    {
        return new SearchConsoleService();
    }
}
