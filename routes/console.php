<?php

use App\Jobs\RecheckProductRequestMappingsJob;
use App\Jobs\WarmSkuCacheJob;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
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

// The chat delivery buffer lives in the file-backed 'chat' cache store and
// expires on its own — but the file driver, like the database one above, only
// reclaims an entry when that exact key is read again. A conversation nobody
// reopens leaves its file behind for good, so sweep the directory by mtime
// instead of waiting for a read that may never come.
//
// Anything untouched for twice the delivery window is finished by definition:
// the entry inside it has already expired, and no client is still polling it.
// The people talking keep their own copies in their browsers, so nothing of
// value is lost here.
$pruneStaleChat = function () {
    $root = config('cache.stores.chat.path');

    if (!$root || !is_dir($root)) {
        return;
    }

    $cutoff = now()->timestamp - (\App\Support\EphemeralChat::BUFFER_TTL * 2);

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        if ($entry->isFile() && $entry->getMTime() < $cutoff) {
            @unlink($entry->getPathname());
        } elseif ($entry->isDir()) {
            // The file driver nests keys two levels deep; drop the hash
            // directories once their contents are gone.
            @rmdir($entry->getPathname());
        }
    }
};

Schedule::call($pruneStaleChat)->hourly()->name('prune-stale-chat')->withoutOverlapping();

// Chat attachments. A file is deleted as soon as the message naming it goes —
// when a conversation is cleared, or when it falls off the end of the buffer —
// but not every path gets that chance: the buffer entry can simply expire, and
// then nothing is left that knows the file existed.
//
// So the directory is also swept by mtime, on the same reasoning as the cache
// above. Anything older than the delivery window cannot still be referenced by a
// live message, because the buffer entry naming it has certainly expired.
$pruneChatFiles = function () {
    $root = \App\Support\EphemeralChat::filesPath();

    if (!is_dir($root)) {
        return;
    }

    $cutoff = now()->timestamp - (\App\Support\EphemeralChat::BUFFER_TTL * 2);
    $freed  = 0;

    foreach (glob("{$root}/*") ?: [] as $file) {
        if (is_file($file) && filemtime($file) < $cutoff) {
            $freed += (int) filesize($file);
            @unlink($file);
        }
    }

    // Worth a line in the log: this directory is the one part of chat that can
    // grow without a person deleting anything, and the disk has filled twice.
    if ($freed > 0) {
        \Illuminate\Support\Facades\Log::info('Pruned chat attachments', [
            'freed_mb'    => round($freed / 1048576, 2),
            'remaining_mb' => round(\App\Support\EphemeralChat::totalFileBytes() / 1048576, 2),
        ]);
    }
};

Schedule::call($pruneChatFiles)->hourly()->name('prune-chat-files')->withoutOverlapping();

// Photoroom edits are the heaviest thing this app writes and keeps: a full-size
// edit per image, plus two thumbnails, for every image in a run.
//
// A pushed image drops its full-size copy immediately — Shopify has those bytes
// permanently by then — but that only covers the images somebody actually
// approved. A session reviewed and abandoned, or never opened again, keeps
// everything it produced, and nothing else would ever remove it.
//
// So whole sessions are swept once they are past the retention window. The rows
// stay, with their file paths cleared, so the history still records what was run
// against which folder; only the images go.
$prunePhotoEditorFiles = function () {
    $days   = max(1, (int) config('services.photoroom.retention_days', 7));
    $cutoff = now()->subDays($days);
    $freed  = 0;
    $live   = [];

    PhotoEditSession::select('id')->chunkById(500, function ($sessions) use (&$live) {
        foreach ($sessions as $session) {
            $live[$session->id] = true;
        }
    });

    PhotoEditSession::where('created_at', '<', $cutoff)
        ->whereHas('items', fn ($q) => $q->whereNotNull('edited_path')
            ->orWhereNotNull('edited_thumb_path')
            ->orWhereNotNull('original_thumb_path'))
        ->chunkById(50, function ($sessions) use (&$freed) {
            foreach ($sessions as $session) {
                $freed += $session->deleteFiles();

                PhotoEditItem::where('photo_edit_session_id', $session->id)->update([
                    'edited_path'         => null,
                    'edited_thumb_path'   => null,
                    'original_thumb_path' => null,
                ]);
            }
        });

    // Directories whose session row is already gone — a delete that half
    // finished, or a database restored from behind the filesystem. Nothing
    // references these, so nothing else would ever find them again.
    foreach (glob(storage_path('app/' . PhotoEditSession::STORAGE_ROOT . '/*'), GLOB_ONLYDIR) ?: [] as $dir) {
        if (!isset($live[(int) basename($dir)])) {
            $freed += PhotoEditSession::deleteDirectory($dir);
        }
    }

    if ($freed > 0) {
        \Illuminate\Support\Facades\Log::info('Pruned photo editor files', [
            'freed_mb'     => round($freed / 1048576, 2),
            'remaining_mb' => round(PhotoEditSession::totalBytes() / 1048576, 2),
        ]);
    }
};

Schedule::call($prunePhotoEditorFiles)->daily()->name('prune-photo-editor-files')->withoutOverlapping();

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
        ->whereIn('status', ProductRequest::CLOSED_STATUSES)
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

    // An account copied on every request collects bell entries far faster than
    // anyone clears them, and unread rows were never swept. Six months is long
    // past the point where an unread notice is still worth acting on.
    DB::table('notifications')
        ->whereNull('read_at')
        ->where('created_at', '<', now()->subDays(180))
        ->delete();
};

Schedule::call($pruneProductRequestData)->daily()->name('prune-product-request-data')->withoutOverlapping();

// Chase requests that have gone quiet. Once each weekday morning: a digest is
// only useful if it arrives when someone can act on it, and daily-including-
// weekends would train people to ignore it.
Schedule::command('product-requests:remind')
    ->weekdays()
    ->at('08:30')
    ->name('product-request-reminders')
    ->withoutOverlapping();
