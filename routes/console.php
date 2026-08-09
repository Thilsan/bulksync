<?php

use App\Jobs\RecheckProductRequestMappingsJob;
use App\Jobs\WarmSkuCacheJob;
use App\Models\ProductRequest;
use App\Models\ProductRequestAttachment;
use App\Models\Store;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Warm SKU cache for all stores at set times so user checks are always instant.
// Dispatched to 'maintenance', NOT 'bulkupload': a warm holds a worker for ~an
// hour, and on the shared queue it blocked every user upload behind it four
// times a day. Requires a supervisor worker listening on 'maintenance' with a
// timeout above the job's 10800s — one process only, since concurrent warms
// purge each other's cache rows.
$warmAllStores = function () {
    Store::all()->each(function ($store) {
        WarmSkuCacheJob::dispatch($store->id)->onQueue('maintenance');
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

// The database cache driver only deletes an expired row when that exact key is
// read again. Rows nothing ever reads — abandoned SKU-cache generations, keys
// from a warm that died mid-run — are therefore never reclaimed, and grow until
// the disk fills. Sweep expired rows explicitly, in chunks.
$pruneExpiredCache = function () {
    if (config('cache.default') !== 'database') {
        return; // Redis and friends expire keys on their own
    }

    $connection = config('cache.stores.database.connection') ?: config('database.default');
    $table      = config('cache.stores.database.table', 'cache');

    do {
        $deleted = DB::connection($connection)->table($table)
            ->where('expiration', '<', now()->timestamp)
            ->limit(10000)
            ->delete();
    } while ($deleted > 0);
};

Schedule::call($pruneExpiredCache)->hourly()->name('prune-expired-cache')->withoutOverlapping();

// Product Creation Requests parked in "Waiting for Mapping" are released as soon
// as their SKUs resolve — this is what removes the re-submission step the old
// email process needed. Runs on 'maintenance' so a long read-only Shopify check
// can't sit in front of a user's upload.
Schedule::job(new RecheckProductRequestMappingsJob, 'maintenance')
    ->hourly()
    ->name('recheck-product-request-mappings')
    ->withoutOverlapping();

// Reference images and read notifications are never deleted otherwise. Attachments
// for requests closed over 90 days ago, and read notifications older than 60 days,
// are both safe to drop — the activity log keeps the audit trail either way.
$pruneProductRequestData = function () {
    $cutoff = now()->subDays(90);

    ProductRequestAttachment::whereHas('productRequest', fn ($q) => $q
        ->whereIn('status', [ProductRequest::COMPLETED, ProductRequest::CANCELLED])
        ->where('updated_at', '<', $cutoff))
        ->chunkById(200, function ($attachments) {
            foreach ($attachments as $attachment) {
                $path = storage_path("app/{$attachment->path}");
                if (is_file($path)) {
                    @unlink($path);
                }
                $attachment->delete();
            }
        });

    // Sweep now-empty per-request directories.
    foreach (glob(storage_path('app/product-requests/*'), GLOB_ONLYDIR) as $dir) {
        if (!glob("{$dir}/*")) {
            @rmdir($dir);
        }
    }

    DB::table('notifications')
        ->whereNotNull('read_at')
        ->where('read_at', '<', now()->subDays(60))
        ->delete();
};

Schedule::call($pruneProductRequestData)->daily()->name('prune-product-request-data')->withoutOverlapping();
