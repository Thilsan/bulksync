<?php

use App\Jobs\WarmSkuCacheJob;
use App\Models\Store;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Warm SKU cache for all stores at set times so user checks are always instant
$warmAllStores = function () {
    Store::all()->each(function ($store) {
        WarmSkuCacheJob::dispatch($store->id)->onQueue('bulkupload');
    });
};

Schedule::call($warmAllStores)->dailyAt('00:00')->name('warm-sku-cache-midnight')->withoutOverlapping();
Schedule::call($warmAllStores)->dailyAt('07:30')->name('warm-sku-cache-morning')->withoutOverlapping();
Schedule::call($warmAllStores)->dailyAt('13:20')->name('warm-sku-cache-afternoon')->withoutOverlapping();
Schedule::call($warmAllStores)->dailyAt('19:00')->name('warm-sku-cache-evening')->withoutOverlapping();

// CSV exports (store-sync, sku-checks) are never deleted otherwise — prune anything older than 30 days
$pruneOldExports = function () {
    $cutoff = now()->subDays(30)->timestamp;
    foreach (['store-sync', 'sku-checks'] as $dir) {
        $path = storage_path("app/{$dir}");
        if (!is_dir($path)) continue;
        foreach (glob("{$path}/*") as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
};

Schedule::call($pruneOldExports)->daily()->name('prune-old-csv-exports')->withoutOverlapping();
