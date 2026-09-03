<?php

namespace App\Support;

use App\Models\AiContentItem;
use App\Models\AiContentSession;
use App\Models\ImageAuditSession;
use App\Models\PhotoEditSession;
use App\Models\ProductRequest;
use App\Models\SkuCheckSession;
use App\Models\StoreMigrationSession;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;

/**
 * What this workspace has been doing — the home screen's numbers, in one place.
 *
 * Every module keeps its own history: uploads, SKU checks, image audits,
 * migrations, AI content and product creation requests. Before this existed
 * you opened six screens to answer "what happened today and what is still
 * running". This gathers it in one pass — headline figures, a fortnight of
 * throughput, whatever is running right now, and one merged timeline.
 *
 * This feeds the AI Studio tab of the management dashboard only. It is a
 * deliberate copy of what DashboardController does for the home screen, which
 * is left exactly as it is by request — so the two can drift, and a change to
 * one has to be made in the other by hand. The paired view is
 * resources/views/orders/studio.blade.php.
 *
 * Everything is scoped the way the modules scope themselves — a super admin
 * sees the whole workspace, everyone else sees their own work — and a module
 * the user has no permission for is never queried at all.
 */
class WorkspaceSummary
{
    /** How far back the "recent" per-module counts look. */
    private const TREND_DAYS = 14;

    /**
     * A job is only "running" if something has touched it lately.
     *
     * Every module queues its work and bumps the session row as it progresses,
     * so a live job is never quiet for long. A row left pending or processing
     * beyond this window is stalled — a stopped queue worker, a killed process
     * — and showing it as active is worse than not showing it at all: the panel
     * fills with checks that will never move and hides the ones that will.
     */
    private const STALE_AFTER_MINUTES = 30;

    /** Everything the dashboard view needs, keyed the way the view reads it. */
    public static function for(User $user): array
    {
        return (new self)->build($user);
    }

    private function build(User $user): array
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
        $photo     = $can('photo_editor')     ? $this->photoStats($mine, $since)     : null;
        $requests  = $can('product_request')  ? $this->requestStats($user)           : null;

        return [
            'user'        => $user,
            'greeting'    => $this->greeting(),
            'headline'    => $this->headline($upload, $sku, $ai, $requests, $audit),
            'modules'     => $this->modules($upload, $sku, $audit, $migration, $ai, $requests, $photo),

            // No throughput chart on this tab, so the six grouped queries that
            // fed it are not run. The greeting still counts what is live.
            'running'     => $this->running($mine, $can),
        ];
    }

    // ── Per-module roll-ups ──────────────────────────────────────────────────

    /**
     * The statuses each module uses while work is still in flight.
     *
     * They do not agree: the SKU checker and image audit call it "running",
     * uploads call it "processing", and AI content adds a translation pass on
     * the end. Anything not listed here is finished, one way or another.
     */
    private const BUSY_STATUSES = [
        UploadSession::class         => ['pending', 'processing'],
        SkuCheckSession::class       => ['pending', 'running'],
        ImageAuditSession::class     => ['pending', 'running'],
        AiContentSession::class      => ['pending', 'processing', 'translating'],
        PhotoEditSession::class      => ['pending', 'processing'],
        StoreMigrationSession::class => ['pending', 'running'],
    ];

    /** Narrows a session query to work that is genuinely in flight. */
    private function live($query)
    {
        $busy = self::BUSY_STATUSES[$query->getModel()::class] ?? ['pending', 'processing'];

        return $query
            ->whereIn('status', $busy)
            ->where('updated_at', '>=', Carbon::now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

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
            'running'   => $this->live((clone $all))->count(),
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
            'running'   => $this->live((clone $all))->count(),
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
            'running'  => $this->live((clone $all))->count(),
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
            'running' => $this->live((clone $all))->count(),
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
            'running'   => $this->live((clone $all))->count(),
            'latest'    => (clone $all)->with('store')->latest()->first(),
        ];
    }

    /**
     * Photoroom edits: what was cut out, and how much of it reached a product.
     *
     * A session sits in 'configuring' between the folder scan and someone
     * choosing the edits, which is waiting on a person rather than work in
     * flight — it is counted separately from the running total for that reason.
     */
    private function photoStats(callable $mine, Carbon $since): array
    {
        $all = $mine(PhotoEditSession::class);

        return [
            'sessions'  => (clone $all)->count(),
            'recent'    => (clone $all)->where('created_at', '>=', $since)->count(),
            'scanned'   => (int) (clone $all)->sum('scanned_files'),
            'edited'    => (int) (clone $all)->sum('edited_files'),
            'pushed'    => (int) (clone $all)->sum('pushed_files'),
            'failed'    => (int) (clone $all)->sum('failed_files'),
            'running'   => $this->live((clone $all))->count(),
            'awaiting'  => (clone $all)->where('status', 'configuring')->count(),
        ];
    }

    /**
     * Product creation requests are scoped by desk, not by owner: the whole
     * point of the module is that a request passes through several teams.
     *
     * Only what the headline tile and the module card read — this tab shows no
     * pipeline of its own, so the stage counts and the latest-request list the
     * home dashboard gathers are not queried here.
     */
    private function requestStats(User $user): array
    {
        $desk = fn () => ProductRequest::query()->onMyDesk($user);

        $open = $desk()->whereNotIn('status', ProductRequest::CLOSED_STATUSES);

        return [
            'open'      => (clone $open)->count(),
            'on_hold'   => $desk()->onHold()->count(),
            'overdue'   => (clone $open)
                ->whereNotNull('online_launch_date')
                ->whereDate('online_launch_date', '<', Carbon::today())
                ->count(),
            'published' => $desk()->whereIn('status', [ProductRequest::PUBLISHED, ProductRequest::COMPLETED])->count(),
            'skus'      => (int) $desk()->sum('total_skus'),
            'mapped'    => (int) $desk()->sum('mapped_skus'),
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
    private function modules(?array $upload, ?array $sku, ?array $audit, ?array $migration, ?array $ai, ?array $requests, ?array $photo = null): array
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

        if ($photo) {
            $cards[] = [
                'name'    => 'Photo Editor',
                'blurb'   => 'Backgrounds cut out and retouched through Photoroom.',
                'route'   => 'photo-editor.index',
                'link'    => 'New edit',
                'tone'    => 'rose',
                'icon'    => 'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z',
                'running' => $photo['running'],
                'metrics' => [
                    ['Sessions', number_format($photo['sessions'])],
                    ['Edited',   number_format($photo['edited'])],
                    ['Pushed',   number_format($photo['pushed'])],
                ],

                // Editing an image costs money; pushing it is what makes that
                // money worth spending, so the bar tracks the second one.
                'bar'     => $this->ratio($photo['pushed'], $photo['edited']),
                'barNote' => $photo['edited'] > 0
                    ? number_format($photo['pushed']) . ' of ' . number_format($photo['edited']) . ' edited images pushed to a product'
                        . ($photo['awaiting'] > 0 ? ' · ' . number_format($photo['awaiting']) . ' waiting to be set up' : '')
                    : 'No images edited yet',
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

    // ── Live work ────────────────────────────────────────────────────────────

    /** Anything still moving, across every module, newest first. */
    private function running(callable $mine, callable $can): Collection
    {
        $live = collect();

        if ($can('bulk_upload')) {
            $live = $live->concat(
                $this->live($mine(UploadSession::class))->latest()->limit(5)->get()
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
                $this->live($mine(SkuCheckSession::class))->latest()->limit(5)->get()
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
                $this->live($mine(ImageAuditSession::class))->latest()->limit(5)->get()
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
                $this->live($mine(AiContentSession::class))->latest()->limit(5)->get()
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
                $this->live($mine(StoreMigrationSession::class))->latest()->limit(5)->get()
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
