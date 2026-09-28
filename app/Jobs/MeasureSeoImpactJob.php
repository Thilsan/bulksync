<?php

namespace App\Jobs;

use App\Models\SeoContentPush;
use App\Models\Store;
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

            if (!$store?->ga4_property_id) {
                $this->giveUp($pushes, 'no_data', 'No GA4 property is set for this website.');
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

        try {
            $sessions = $this->impactService()->sessionsByHandle(
                (string) $store->ga4_property_id,
                $beforeFrom, $beforeTo, $afterFrom, $afterTo,
            );
        } catch (\Throwable $e) {
            Log::warning('MeasureSeoImpactJob: GA4 read failed', [
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

            // A page with no organic sessions on either side is reported as
            // having no data rather than as a flat zero-percent result, which
            // would read as "the rewrite changed nothing" when in truth nobody
            // was ever there to count.
            $push->update([
                'sessions_before'    => $before,
                'sessions_after'     => $after,
                'measured_at'        => now(),
                'measurement_status' => ($before === 0 && $after === 0) ? 'no_data' : 'measured',
                'measurement_note'   => ($before === 0 && $after === 0)
                    ? 'No organic sessions to this URL in either window.'
                    : null,
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
}
