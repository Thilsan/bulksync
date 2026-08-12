<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AiContentItem;
use App\Models\AiContentSession;
use App\Models\ImageAuditSession;
use App\Models\ProductRequest;
use App\Models\SkuCheckSession;
use App\Models\Store;
use App\Models\StoreMigrationSession;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The home screen of AI Ecommerce Studio.
 *
 * Every module in this system keeps its own history — uploads, SKU checks,
 * image audits, migrations, AI content and product creation requests. Before
 * this page existed you had to open six screens to answer "what happened
 * today and what is still running". This controller gathers that in one pass:
 * headline numbers, a fortnight of throughput, whatever is running right now,
 * and one merged timeline across every module.
 *
 * Everything is scoped the same way the modules scope themselves — a super
 * admin sees the whole workspace, everyone else sees their own work — and a
 * module the user has no permission for is never queried at all.
 */
class DashboardController extends Controller
{
    /** How far back the throughput chart and the "recent" numbers look. */
    private const TREND_DAYS = 14;

    public function index(#[CurrentUser] User $user): View
    {
        $can = fn (string $feature) => $user->hasFeature($feature);

        // Every module query starts here, so ownership rules live in one place.
        $mine = function (string $model) use ($user) {
            $query = $model::query();

            return $user->is_super_admin ? $query : $query->where('user_id', $user->id);
        };

        $since = Carbon::today()->subDays(self::TREND_DAYS - 1);

        $upload    = $can('bulk_upload')      ? $this->uploadStats($mine, $since)    : null;
        $sku       = $can('sku_checker')      ? $this->skuStats($mine, $since)       : null;
        $audit     = $can('image_audit')      ? $this->auditStats($mine, $since)     : null;
        $migration = $can('store_sync')       ? $this->migrationStats($mine, $since) : null;
        $ai        = $can('ai_content')       ? $this->aiStats($mine, $since)        : null;
        $requests  = $can('product_request')  ? $this->requestStats($user)           : null;

        return view('dashboard', [
            'user'        => $user,
            'greeting'    => $this->greeting(),
            'headline'    => $this->headline($upload, $sku, $ai, $requests, $audit),
            'modules'     => $this->modules($upload, $sku, $audit, $migration, $ai, $requests),
            'trend'       => $this->trend($user, $since),
            'running'     => $this->running($mine, $can),
            'requests'    => $requests,
            'launches'    => $can('product_request') ? $this->launches($user) : collect(),
            'photoshoots' => $can('product_request') ? $this->photoshoots($user) : collect(),
            'feed'        => $this->feed($mine, $can),
            'stores'      => $this->stores($user),
            'health'      => $this->health($user),
            'team'        => $user->is_super_admin ? $this->team() : collect(),
            'trendDays'   => self::TREND_DAYS,
        ]);
    }

    // ── Per-module roll-ups ──────────────────────────────────────────────────

    private function uploadStats(callable $mine, Carbon $since): array
    {
        $all = $mine(UploadSession::class);

        return [
            'sessions'  => (clone $all)->count(),
            'recent'    => (clone $all)->where('created_at', '>=', $since)->count(),
            'uploaded'  => (int) (clone $all)->sum('uploaded_files'),
            'matched'   => (int) (clone $all)->sum('matched_files'),
            'skipped'   => (int) (clone $all)->sum('skipped_files'),
            'failed'    => (int) (clone $all)->sum('failed_files'),
            'scanned'   => (int) (clone $all)->sum('total_files'),
            'running'   => (clone $all)->whereIn('status', ['pending', 'processing'])->count(),
            'latest'    => (clone $all)->latest()->first(),
        ];
    }

    private function skuStats(callable $mine, Carbon $since): array
    {
        $all = $mine(SkuCheckSession::class);

        return [
            'checks'    => (clone $all)->count(),
            'recent'    => (clone $all)->where('created_at', '>=', $since)->count(),
            'skus'      => (int) (clone $all)->sum('total_skus'),
            'available' => (int) (clone $all)->sum('available_count'),
            'missing'   => (int) (clone $all)->sum('not_available_count'),
            'running'   => (clone $all)->whereIn('status', ['pending', 'processing'])->count(),
            'sessions'  => (clone $all)->with('store')->latest()->limit(5)->get(),
        ];
    }

    private function auditStats(callable $mine, Carbon $since): array
    {
        $all = $mine(ImageAuditSession::class);

        return [
            'audits'   => (clone $all)->count(),
            'recent'   => (clone $all)->where('created_at', '>=', $since)->count(),
            'products' => (int) (clone $all)->sum('total_products'),
            'with'     => (int) (clone $all)->sum('with_images'),
            'without'  => (int) (clone $all)->sum('without_images'),
            'running'  => (clone $all)->whereIn('status', ['pending', 'processing'])->count(),
            'latest'   => (clone $all)->with('store')->latest()->first(),
        ];
    }

    private function migrationStats(callable $mine, Carbon $since): array
    {
        $all = $mine(StoreMigrationSession::class);

        return [
            'runs'    => (clone $all)->count(),
            'recent'  => (clone $all)->where('created_at', '>=', $since)->count(),
            'skus'    => (int) (clone $all)->sum('total_skus'),
            'success' => (int) (clone $all)->sum('success_count'),
            'failed'  => (int) (clone $all)->sum('failed_count'),
            'running' => (clone $all)->whereIn('status', ['pending', 'processing'])->count(),
            'latest'  => (clone $all)->with(['fromStore', 'toStore'])->latest()->first(),
        ];
    }

    private function aiStats(callable $mine, Carbon $since): array
    {
        $all = $mine(AiContentSession::class);

        // Item-level numbers are what people actually care about ("how many
        // descriptions were written"), so they are counted through the session
        // scope rather than off the sessions table.
        $itemIds = (clone $all)->select('id');

        return [
            'sessions'  => (clone $all)->count(),
            'recent'    => (clone $all)->where('created_at', '>=', $since)->count(),
            'items'     => (int) (clone $all)->sum('total_items'),
            'processed' => (int) (clone $all)->sum('processed_items'),
            'confirmed' => AiContentItem::whereIn('session_id', $itemIds)->where('is_confirmed', true)->count(),
            'translated'=> AiContentItem::whereIn('session_id', $itemIds)->whereNotNull('ai_description_ar')->count(),
            'running'   => (clone $all)->whereIn('status', ['pending', 'processing'])->count(),
            'latest'    => (clone $all)->with('store')->latest()->first(),
        ];
    }

    /**
     * Product creation requests are scoped by desk, not by owner: the whole
     * point of the module is that a request passes through several teams.
     */
    private function requestStats(User $user): array
    {
        $desk = fn () => ProductRequest::query()->onMyDesk($user);

        $byStage = $desk()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $open = $desk()->whereNotIn('status', ProductRequest::CLOSED_STATUSES);

        return [
            'total'     => $desk()->count(),
            'open'      => (clone $open)->count(),
            'on_hold'   => $desk()->onHold()->count(),
            'overdue'   => (clone $open)
                ->whereNotNull('online_launch_date')
                ->whereDate('online_launch_date', '<', Carbon::today())
                ->count(),
            'published' => $desk()->whereIn('status', [ProductRequest::PUBLISHED, ProductRequest::COMPLETED])->count(),
            'mine'      => ProductRequest::query()->assignedTo($user)
                ->whereNotIn('status', ProductRequest::CLOSED_STATUSES)->count(),
            'skus'      => (int) $desk()->sum('total_skus'),
            'mapped'    => (int) $desk()->sum('mapped_skus'),
            'unmapped'  => (int) $desk()->sum('not_mapped_skus'),
            'by_stage'  => $byStage,
            'recent'    => $desk()->with(['user', 'store'])->latest()->limit(6)->get(),
        ];
    }

    // ── Headline numbers ─────────────────────────────────────────────────────

    /**
     * Four numbers that describe the workspace at a glance. Which four depends
     * on what the user can see — an account with only the SKU checker should
     * not stare at three empty tiles.
     */
    private function headline(?array $upload, ?array $sku, ?array $ai, ?array $requests, ?array $audit): array
    {
        $tiles = [];

        if ($upload) {
            $tiles[] = [
                'label'  => 'Images Uploaded',
                'value'  => $upload['uploaded'],
                'note'   => number_format($upload['sessions']) . ' upload ' . str('session')->plural($upload['sessions']),
                'tone'   => 'brand',
                'icon'   => 'M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12',
                'route'  => 'upload.history',
            ];
        }

        if ($sku) {
            $tiles[] = [
                'label'  => 'SKUs Verified',
                'value'  => $sku['skus'],
                'note'   => number_format($sku['available']) . ' found · ' . number_format($sku['missing']) . ' missing',
                'tone'   => 'emerald',
                'icon'   => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                'route'  => 'sku-checker.history',
            ];
        }

        if ($ai) {
            $tiles[] = [
                'label'  => 'AI Content Written',
                'value'  => $ai['processed'],
                'note'   => number_format($ai['translated']) . ' also in Arabic',
                'tone'   => 'violet',
                'icon'   => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
                'route'  => 'ai-content.index',
            ];
        }

        if ($requests) {
            $tiles[] = [
                'label'  => 'Open Requests',
                'value'  => $requests['open'],
                'note'   => $requests['overdue'] > 0
                    ? number_format($requests['overdue']) . ' past launch date'
                    : number_format($requests['published']) . ' published to date',
                'tone'   => $requests['overdue'] > 0 ? 'rose' : 'amber',
                'icon'   => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
                'route'  => 'product-requests.index',
            ];
        }

        if ($audit && count($tiles) < 4) {
            $tiles[] = [
                'label'  => 'Products Audited',
                'value'  => $audit['products'],
                'note'   => number_format($audit['without']) . ' with no image',
                'tone'   => 'sky',
                'icon'   => 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
                'route'  => 'image-audit.index',
            ];
        }

        return array_slice($tiles, 0, 4);
    }

    // ── Module cards ─────────────────────────────────────────────────────────

    /**
     * One card per tool the user owns, each carrying the two or three numbers
     * that tool is actually about plus a health line.
     */
    private function modules(?array $upload, ?array $sku, ?array $audit, ?array $migration, ?array $ai, ?array $requests): array
    {
        $cards = [];

        if ($upload) {
            $done = $upload['uploaded'] + $upload['skipped'] + $upload['failed'];
            $cards[] = [
                'name'    => 'Image Upload',
                'blurb'   => 'OneDrive folder to Shopify, matched by SKU.',
                'route'   => 'upload.create',
                'link'    => 'New upload',
                'tone'    => 'brand',
                'icon'    => 'M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12',
                'running' => $upload['running'],
                'metrics' => [
                    ['Sessions',  number_format($upload['sessions'])],
                    ['Uploaded',  number_format($upload['uploaded'])],
                    ['No match',  number_format($upload['skipped'])],
                ],
                'bar'     => $this->ratio($upload['uploaded'], $done),
                'barNote' => $done > 0
                    ? $this->ratio($upload['uploaded'], $done) . '% of processed files landed on a product'
                    : 'No files processed yet',
            ];
        }

        if ($sku) {
            $checked = $sku['available'] + $sku['missing'];
            $cards[] = [
                'name'    => 'SKU Checker',
                'blurb'   => 'Which SKUs already exist on the store.',
                'route'   => 'sku-checker.index',
                'link'    => 'Run a check',
                'tone'    => 'emerald',
                'icon'    => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                'running' => $sku['running'],
                'metrics' => [
                    ['Checks',    number_format($sku['checks'])],
                    ['Available', number_format($sku['available'])],
                    ['Not found', number_format($sku['missing'])],
                ],
                'bar'     => $this->ratio($sku['available'], $checked),
                'barNote' => $checked > 0
                    ? $this->ratio($sku['available'], $checked) . '% of checked SKUs are live'
                    : 'No SKUs checked yet',
            ];
        }

        if ($audit) {
            $seen = $audit['with'] + $audit['without'];
            $cards[] = [
                'name'    => 'Image Audit',
                'blurb'   => 'Catalogue scan for products missing images.',
                'route'   => 'image-audit.index',
                'link'    => 'Start an audit',
                'tone'    => 'sky',
                'icon'    => 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
                'running' => $audit['running'],
                'metrics' => [
                    ['Audits',      number_format($audit['audits'])],
                    ['With images', number_format($audit['with'])],
                    ['Missing',     number_format($audit['without'])],
                ],
                'bar'     => $this->ratio($audit['with'], $seen),
                'barNote' => $seen > 0
                    ? $this->ratio($audit['with'], $seen) . '% of audited products have imagery'
                    : 'No audit has run yet',
            ];
        }

        if ($ai) {
            $cards[] = [
                'name'    => 'AI Content Generator',
                'blurb'   => 'Descriptions, titles and meta, English and Arabic.',
                'route'   => 'ai-content.index',
                'link'    => 'Generate content',
                'tone'    => 'violet',
                'icon'    => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
                'running' => $ai['running'],
                'metrics' => [
                    ['Sessions',  number_format($ai['sessions'])],
                    ['Written',   number_format($ai['processed'])],
                    ['Confirmed', number_format($ai['confirmed'])],
                ],
                'bar'     => $this->ratio($ai['processed'], $ai['items']),
                'barNote' => $ai['items'] > 0
                    ? number_format($ai['processed']) . ' of ' . number_format($ai['items']) . ' queued products written'
                    : 'Nothing queued for writing',
            ];
        }

        if ($migration) {
            $attempted = $migration['success'] + $migration['failed'];
            $cards[] = [
                'name'    => 'Product Migration',
                'blurb'   => 'Copy products and imagery between stores.',
                'route'   => 'store-image-sync.index',
                'link'    => 'New migration',
                'tone'    => 'indigo',
                'icon'    => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
                'running' => $migration['running'],
                'metrics' => [
                    ['Runs',      number_format($migration['runs'])],
                    ['Succeeded', number_format($migration['success'])],
                    ['Failed',    number_format($migration['failed'])],
                ],
                'bar'     => $this->ratio($migration['success'], $attempted),
                'barNote' => $attempted > 0
                    ? $this->ratio($migration['success'], $attempted) . '% of migrated SKUs succeeded'
                    : 'No migration has run yet',
            ];
        }

        if ($requests) {
            $cards[] = [
                'name'    => 'Product Creation Request',
                'blurb'   => 'New products from brand request to live launch.',
                'route'   => 'product-requests.index',
                'link'    => 'Open the board',
                'tone'    => 'amber',
                'icon'    => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
                'running' => $requests['open'],
                'metrics' => [
                    ['Open',      number_format($requests['open'])],
                    ['On hold',   number_format($requests['on_hold'])],
                    ['Published', number_format($requests['published'])],
                ],
                'bar'     => $this->ratio($requests['mapped'], $requests['skus']),
                'barNote' => $requests['skus'] > 0
                    ? number_format($requests['mapped']) . ' of ' . number_format($requests['skus']) . ' requested SKUs mapped'
                    : 'No SKUs requested yet',
            ];
        }

        return $cards;
    }

    // ── Throughput chart ─────────────────────────────────────────────────────

    /**
     * A day-by-day count of jobs started, per module, for the trend window.
     * Returned pre-padded so the view can draw bars without any date logic.
     */
    private function trend(User $user, Carbon $since): array
    {
        $series = [
            'Uploads'    => ['model' => UploadSession::class,         'feature' => 'bulk_upload',     'class' => 'bg-brand-500'],
            'SKU checks' => ['model' => SkuCheckSession::class,       'feature' => 'sku_checker',     'class' => 'bg-emerald-500'],
            'Audits'     => ['model' => ImageAuditSession::class,     'feature' => 'image_audit',     'class' => 'bg-sky-500'],
            'AI content' => ['model' => AiContentSession::class,      'feature' => 'ai_content',      'class' => 'bg-violet-500'],
            'Migrations' => ['model' => StoreMigrationSession::class, 'feature' => 'store_sync',      'class' => 'bg-indigo-500'],
            'Requests'   => ['model' => ProductRequest::class,        'feature' => 'product_request', 'class' => 'bg-amber-500'],
        ];

        $days = collect(range(0, self::TREND_DAYS - 1))
            ->map(fn ($offset) => $since->copy()->addDays($offset))
            ->keyBy(fn (Carbon $day) => $day->toDateString());

        $legend = [];
        $totals = $days->map(fn () => [])->all();

        foreach ($series as $label => $meta) {
            if (! $user->hasFeature($meta['feature'])) {
                continue;
            }

            $query = $meta['model']::query()->where('created_at', '>=', $since);

            if (! $user->is_super_admin) {
                $query->where('user_id', $user->id);
            }

            $counts = $query
                ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
                ->groupBy('day')
                ->pluck('aggregate', 'day');

            $legend[$label] = [
                'class' => $meta['class'],
                'total' => (int) $counts->sum(),
            ];

            foreach ($totals as $date => $stack) {
                $count = (int) ($counts[$date] ?? 0);
                if ($count > 0) {
                    $totals[$date][] = ['label' => $label, 'class' => $meta['class'], 'count' => $count];
                }
            }
        }

        $bars = [];
        foreach ($days as $date => $day) {
            $stack = $totals[$date] ?? [];
            $bars[] = [
                'date'  => $day,
                'total' => array_sum(array_column($stack, 'count')),
                'stack' => $stack,
            ];
        }

        $peak = max(1, max(array_column($bars, 'total') ?: [0]));

        return [
            'bars'   => $bars,
            'peak'   => $peak,
            'legend' => $legend,
            'total'  => array_sum(array_column($bars, 'total')),
        ];
    }

    // ── Live work ────────────────────────────────────────────────────────────

    /** Anything still moving, across every module, newest first. */
    private function running(callable $mine, callable $can): Collection
    {
        $live = collect();
        $busy = ['pending', 'processing'];

        if ($can('bulk_upload')) {
            $live = $live->concat(
                $mine(UploadSession::class)->whereIn('status', $busy)->latest()->limit(5)->get()
                    ->map(fn (UploadSession $s) => [
                        'module'   => 'Image Upload',
                        'tone'     => 'brand',
                        'title'    => $s->name ?: 'Upload session',
                        'detail'   => number_format($s->uploaded_files) . ' of ' . number_format($s->total_files) . ' files',
                        'percent'  => $s->progressPercent(),
                        'status'   => $s->status,
                        'started'  => $s->created_at,
                        'url'      => route('upload.show', $s),
                    ])
            );
        }

        if ($can('sku_checker')) {
            $live = $live->concat(
                $mine(SkuCheckSession::class)->whereIn('status', $busy)->latest()->limit(5)->get()
                    ->map(fn (SkuCheckSession $s) => [
                        'module'  => 'SKU Checker',
                        'tone'    => 'emerald',
                        'title'   => $s->name ?: 'SKU check',
                        'detail'  => number_format($s->scanned_skus) . ' of ' . number_format($s->total_skus) . ' SKUs',
                        'percent' => $s->progressPercent(),
                        'status'  => $s->status,
                        'started' => $s->created_at,
                        'url'     => route('sku-checker.show', $s),
                    ])
            );
        }

        if ($can('image_audit')) {
            $live = $live->concat(
                $mine(ImageAuditSession::class)->whereIn('status', $busy)->latest()->limit(5)->get()
                    ->map(fn (ImageAuditSession $s) => [
                        'module'  => 'Image Audit',
                        'tone'    => 'sky',
                        'title'   => 'Audit #' . $s->id,
                        'detail'  => number_format($s->scanned_products) . ' of ' . number_format($s->total_products) . ' products',
                        'percent' => $s->progressPercent(),
                        'status'  => $s->status,
                        'started' => $s->created_at,
                        'url'     => route('image-audit.show', $s),
                    ])
            );
        }

        if ($can('ai_content')) {
            $live = $live->concat(
                $mine(AiContentSession::class)->whereIn('status', $busy)->latest()->limit(5)->get()
                    ->map(fn (AiContentSession $s) => [
                        'module'  => 'AI Content',
                        'tone'    => 'violet',
                        'title'   => 'Content session #' . $s->id,
                        'detail'  => number_format($s->processed_items) . ' of ' . number_format($s->total_items) . ' products',
                        'percent' => $s->progressPercent(),
                        'status'  => $s->status,
                        'started' => $s->created_at,
                        'url'     => route('ai-content.show', $s),
                    ])
            );
        }

        if ($can('store_sync')) {
            $live = $live->concat(
                $mine(StoreMigrationSession::class)->whereIn('status', $busy)->latest()->limit(5)->get()
                    ->map(function (StoreMigrationSession $s) {
                        $done = $s->success_count + $s->failed_count;

                        return [
                            'module'  => 'Product Migration',
                            'tone'    => 'indigo',
                            'title'   => 'Migration ' . strtoupper(substr($s->token, 0, 6)),
                            'detail'  => number_format($done) . ' of ' . number_format($s->total_skus) . ' SKUs',
                            'percent' => $this->ratio($done, $s->total_skus),
                            'status'  => $s->status,
                            'started' => $s->created_at,
                            'url'     => route('store-image-sync.show', $s->token),
                        ];
                    })
            );
        }

        return $live->sortByDesc('started')->values()->take(6);
    }

    // ── Product request side panels ──────────────────────────────────────────

    /** Requests with a launch date coming up (or already missed). */
    private function launches(User $user): Collection
    {
        return ProductRequest::query()->onMyDesk($user)
            ->whereNotIn('status', ProductRequest::CLOSED_STATUSES)
            ->whereNotNull('online_launch_date')
            ->orderBy('online_launch_date')
            ->limit(5)
            ->get();
    }

    /** Shoots that are booked or in the room right now. */
    private function photoshoots(User $user): Collection
    {
        return ProductRequest::query()->onMyDesk($user)
            ->whereIn('photoshoot_status', ProductRequest::SHOOT_OPEN_STATUSES)
            ->orderByRaw('photoshoot_scheduled_at IS NULL')
            ->orderBy('photoshoot_scheduled_at')
            ->limit(4)
            ->get();
    }

    // ── Merged timeline ──────────────────────────────────────────────────────

    /**
     * One chronological list of finished work across every module — the answer
     * to "what has this workspace been doing", without opening six screens.
     */
    private function feed(callable $mine, callable $can): Collection
    {
        $events = collect();

        if ($can('bulk_upload')) {
            $events = $events->concat(
                $mine(UploadSession::class)->with('user')->latest()->limit(6)->get()
                    ->map(fn (UploadSession $s) => [
                        'module' => 'Image Upload',
                        'tone'   => 'brand',
                        'title'  => $s->name ?: 'Upload session',
                        'detail' => number_format($s->uploaded_files) . ' uploaded · '
                                    . number_format($s->skipped_files) . ' no match · '
                                    . number_format($s->failed_files) . ' failed',
                        'status' => $s->status,
                        'who'    => $s->user?->name,
                        'at'     => $s->created_at,
                        'url'    => route('upload.show', $s),
                    ])
            );
        }

        if ($can('sku_checker')) {
            $events = $events->concat(
                $mine(SkuCheckSession::class)->with(['user', 'store'])->latest()->limit(6)->get()
                    ->map(fn (SkuCheckSession $s) => [
                        'module' => 'SKU Checker',
                        'tone'   => 'emerald',
                        'title'  => $s->name ?: 'SKU check on ' . ($s->store?->name ?? 'store'),
                        'detail' => number_format($s->total_skus) . ' checked · '
                                    . number_format($s->available_count) . ' available · '
                                    . number_format($s->not_available_count) . ' not found',
                        'status' => $s->status,
                        'who'    => $s->user?->name,
                        'at'     => $s->created_at,
                        'url'    => route('sku-checker.show', $s),
                    ])
            );
        }

        if ($can('image_audit')) {
            $events = $events->concat(
                $mine(ImageAuditSession::class)->with(['user', 'store'])->latest()->limit(4)->get()
                    ->map(fn (ImageAuditSession $s) => [
                        'module' => 'Image Audit',
                        'tone'   => 'sky',
                        'title'  => 'Audit of ' . ($s->store?->name ?? 'store'),
                        'detail' => number_format($s->total_products) . ' products · '
                                    . number_format($s->without_images) . ' missing images',
                        'status' => $s->status,
                        'who'    => $s->user?->name,
                        'at'     => $s->created_at,
                        'url'    => route('image-audit.show', $s),
                    ])
            );
        }

        if ($can('ai_content')) {
            $events = $events->concat(
                $mine(AiContentSession::class)->with(['user', 'store'])->latest()->limit(4)->get()
                    ->map(fn (AiContentSession $s) => [
                        'module' => 'AI Content',
                        'tone'   => 'violet',
                        'title'  => 'Content for ' . ($s->store?->name ?? 'store'),
                        'detail' => number_format($s->processed_items) . ' of '
                                    . number_format($s->total_items) . ' products written',
                        'status' => $s->status,
                        'who'    => $s->user?->name,
                        'at'     => $s->created_at,
                        'url'    => route('ai-content.show', $s),
                    ])
            );
        }

        if ($can('store_sync')) {
            $events = $events->concat(
                $mine(StoreMigrationSession::class)->with(['user', 'fromStore', 'toStore'])->latest()->limit(4)->get()
                    ->map(fn (StoreMigrationSession $s) => [
                        'module' => 'Product Migration',
                        'tone'   => 'indigo',
                        'title'  => ($s->fromStore?->name ?? 'Source') . ' → ' . ($s->toStore?->name ?? 'Target'),
                        'detail' => number_format($s->success_count) . ' migrated · '
                                    . number_format($s->failed_count) . ' failed',
                        'status' => $s->status,
                        'who'    => $s->user?->name,
                        'at'     => $s->created_at,
                        'url'    => route('store-image-sync.show', $s->token),
                    ])
            );
        }

        return $events
            ->filter(fn (array $e) => $e['at'] !== null)
            ->sortByDesc('at')
            ->values()
            ->take(8);
    }

    // ── Workspace context ────────────────────────────────────────────────────

    /** The stores this account can reach, and how much work each has taken. */
    private function stores(User $user): Collection
    {
        $stores = $user->is_super_admin
            ? Store::orderBy('name')->get()
            : $user->stores()->orderBy('name')->get();

        return $stores->map(function (Store $store) use ($user) {
            $scope = function ($query) use ($user) {
                return $user->is_super_admin ? $query : $query->where('user_id', $user->id);
            };

            return [
                'model'      => $store,
                'connected'  => filled($store->shopify_access_token),
                'uploads'    => $scope(UploadSession::where('store_id', $store->id))->count(),
                'checks'     => $scope(SkuCheckSession::where('store_id', $store->id))->count(),
                'requests'   => $scope(ProductRequest::where('store_id', $store->id))->count(),
            ];
        });
    }

    /**
     * Integrations this workspace leans on. A dead OneDrive connection is the
     * single most common reason an upload fails, so it is on the front page
     * rather than three clicks into settings.
     */
    private function health(User $user): array
    {
        $activeStore = Store::where('is_active', true)->first();

        return [
            [
                'name'   => 'OneDrive',
                'ok'     => filled($user->onedrive_refresh_token),
                'detail' => filled($user->onedrive_refresh_token)
                    ? 'Connected — image folders can be read'
                    : 'Not connected — uploads cannot read folders',
                'route'  => 'settings.index',
            ],
            [
                'name'   => 'Shopify',
                'ok'     => $activeStore !== null && filled($activeStore->shopify_access_token),
                'detail' => $activeStore
                    ? ($activeStore->name . ' is the active store')
                    : 'No active store selected',
                'route'  => 'stores.index',
            ],
            [
                'name'   => 'Gemini AI',
                'ok'     => filled(config('services.gemini.api_key')),
                'detail' => filled(config('services.gemini.api_key'))
                    ? 'Key present — content generation available'
                    : 'No API key — content generation is off',
                'route'  => 'settings.index',
            ],
            [
                'name'   => 'Email',
                'ok'     => \App\Models\Setting::get('mail_enabled') === '1',
                'detail' => \App\Models\Setting::get('mail_enabled') === '1'
                    ? 'Notifications are being sent'
                    : 'Notifications are switched off',
                'route'  => 'settings.index',
            ],
        ];
    }

    /** Super admin only: who has been in the system lately. */
    private function team(): Collection
    {
        $lastSeen = ActivityLog::where('action', ActivityLog::ACTION_LOGIN)
            ->selectRaw('user_id, MAX(created_at) as seen_at')
            ->groupBy('user_id')
            ->pluck('seen_at', 'user_id');

        return User::orderByDesc('is_super_admin')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (User $u) => [
                'name'    => $u->name,
                'email'   => $u->email,
                'role'    => $u->is_super_admin ? 'Super Admin' : ($u->pcrRoleLabel() ?? 'Member'),
                'active'  => (bool) $u->is_active,
                'seen_at' => isset($lastSeen[$u->id]) ? Carbon::parse($lastSeen[$u->id]) : null,
            ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function ratio(int $part, int $whole): int
    {
        return $whole > 0 ? (int) min(100, round($part / $whole * 100)) : 0;
    }

    private function greeting(): string
    {
        $hour = (int) Carbon::now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default    => 'Good evening',
        };
    }
}
