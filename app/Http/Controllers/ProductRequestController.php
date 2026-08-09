<?php

namespace App\Http\Controllers;

use App\Jobs\ValidateProductRequestSkusJob;
use App\Models\ProductRequest;
use App\Models\ProductRequestActivity;
use App\Models\ProductRequestAttachment;
use App\Models\ProductRequestSku;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ProductRequestAssigned;
use App\Notifications\ProductRequestHoldChanged;
use App\Services\ProductRequestWorkflow;
use App\Services\SkuMappingService;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductRequestController extends Controller implements HasMiddleware
{
    /** A single request can't carry more SKUs than this — keeps one bad CSV from flooding the table. */
    public const MAX_SKUS = 5000;

    /**
     * Stage-specific work queues. The photographer and the content team each
     * only care about their own leg of the workflow, so they get a board of
     * just those stages instead of the full request list.
     */
    public const QUEUES = [
        'photoshoot' => [
            'title'          => 'Photoshoot',
            'description'    => 'Requests waiting on images, scheduled shoots and completed shoots.',
            'owner_field'    => 'photographer_id',
            'owner_relation' => 'photographer',
            'owner_label'    => 'Photographer',
            'stages'         => [
                ProductRequest::WAITING_IMAGES,
                ProductRequest::PHOTOSHOOT_SCHEDULED,
                ProductRequest::PHOTOSHOOT_COMPLETED,
            ],
        ],
        'content' => [
            'title'          => 'Content Creation',
            'description'    => 'Requests in image editing and AI content generation.',
            'owner_field'    => 'content_owner_id',
            'owner_relation' => 'contentOwner',
            'owner_label'    => 'Content Owner',
            'stages'         => [
                ProductRequest::IMAGE_EDITING,
                ProductRequest::AI_CONTENT,
            ],
        ],
    ];

    public function __construct(
        private readonly ProductRequestWorkflow $workflow,
        private readonly SkuMappingService $mapping,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, \Closure $next) {
                abort_unless($request->user()?->hasFeature('product_request'), 403, 'You do not have access to Product Creation Requests.');
                return $next($request);
            }),
        ];
    }

    // ── Dashboard ────────────────────────────────────────────────────────────

    public function index(#[CurrentUser] User $user): View
    {
        $base = fn () => ProductRequest::query()->visibleTo($user);

        $stats = [
            'total'             => $base()->count(),
            'pending'           => $base()->where('status', ProductRequest::SUBMITTED)->count(),
            'waiting_mapping'   => $base()->where('status', ProductRequest::WAITING_MAPPING)->count(),
            'in_progress'       => $base()->inProgress()->count(),
            'waiting_photoshoot'=> $base()->whereIn('status', [ProductRequest::WAITING_IMAGES, ProductRequest::PHOTOSHOOT_SCHEDULED])->count(),
            'ready_for_upload'  => $base()->where('status', ProductRequest::READY_FOR_UPLOAD)->count(),
            'published'         => $base()->where('status', ProductRequest::PUBLISHED)->count(),
            'completed'         => $base()->where('status', ProductRequest::COMPLETED)->count(),
        ];

        $breakdown = $base()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $recent = $base()->with(['user', 'assignee'])->latest()->limit(8)->get();

        $deadlines = $base()
            ->whereNotIn('status', [ProductRequest::PUBLISHED, ProductRequest::COMPLETED, ProductRequest::CANCELLED])
            ->whereNotNull('online_launch_date')
            ->orderBy('online_launch_date')
            ->limit(5)
            ->get();

        $topBrands = $base()
            ->selectRaw('brand, COUNT(*) as aggregate')
            ->groupBy('brand')
            ->orderByDesc('aggregate')
            ->limit(5)
            ->pluck('aggregate', 'brand');

        $activity = ProductRequestActivity::with(['user', 'productRequest'])
            ->whereIn('product_request_id', $base()->select('id'))
            ->latest('created_at')
            ->latest('id')
            ->limit(6)
            ->get();

        // Websites the requester can raise a request against.
        $stores = Store::selectableFor($user);

        return view('product-requests.index', compact(
            'stats', 'breakdown', 'recent', 'deadlines', 'topBrands', 'activity', 'stores'
        ));
    }

    /** Full, filterable request list — the "View Requests" screen. */
    public function list(Request $request, #[CurrentUser] User $user): View
    {
        // All four owner relations are eager-loaded: the "Waiting On" column
        // resolves whichever one the current stage belongs to, per row.
        $query = ProductRequest::query()->visibleTo($user)
            ->with(['user', 'assignee', 'photographer', 'contentOwner', 'qaOwner']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }

        if ($request->filled('brand')) {
            $query->where('brand', $request->string('brand'));
        }

        if ($request->filled('search')) {
            $term = '%' . $request->string('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('reference', 'like', $term)
                  ->orWhere('brand', 'like', $term)
                  ->orWhere('category', 'like', $term);
            });
        }

        $requests = $query->latest()->paginate(20)->withQueryString();

        $brands = ProductRequest::query()->visibleTo($user)
            ->distinct()->orderBy('brand')->pluck('brand');

        // The New Request slide-over lives on this page too, and needs the
        // websites this user may file against.
        $stores = Store::selectableFor($user);

        return view('product-requests.list', compact('requests', 'brands', 'stores'));
    }

    /** Everything currently sitting with this user, in any of the four roles. */
    public function myTasks(Request $request, #[CurrentUser] User $user): View
    {
        $query = ProductRequest::query()
            ->assignedTo($user)
            ->with(['user', 'store', 'assignee', 'photographer', 'contentOwner', 'qaOwner']);

        // Closed work is hidden by default — this is a to-do list, not history.
        if (!$request->boolean('include_closed')) {
            $query->whereNotIn('status', [ProductRequest::COMPLETED, ProductRequest::CANCELLED]);
        }

        $requests = $query
            ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByRaw('online_launch_date IS NULL, online_launch_date')
            ->get();

        return view('product-requests.my-tasks', [
            'requests'    => $requests,
            'teamTasks'   => $this->unclaimedForRole($user),
            'overdue'     => $requests->filter->isOverdue()->count(),
            'closedCount' => ProductRequest::query()->assignedTo($user)
                ->whereIn('status', [ProductRequest::COMPLETED, ProductRequest::CANCELLED])->count(),
        ]);
    }

    /**
     * Requests parked at a stage this user's role owns, with nobody's name on
     * them. Without this, unassigned work is invisible to exactly the people
     * who are meant to pick it up.
     *
     * @return \Illuminate\Support\Collection<int, ProductRequest>
     */
    private function unclaimedForRole(User $user)
    {
        if (!$user->pcr_role) {
            return collect();
        }

        $myStages = collect(ProductRequest::STAGE_GUIDE)
            ->filter(fn ($guide) => ($guide['role_key'] ?? null) === $user->pcr_role)
            ->keys();

        if ($myStages->isEmpty()) {
            return collect();
        }

        return ProductRequest::query()
            ->visibleTo($user)
            ->whereIn('status', $myStages)
            ->where(function ($q) use ($myStages) {
                foreach ($myStages as $stage) {
                    $field = ProductRequest::STAGE_GUIDE[$stage]['field'] ?? null;

                    $q->orWhere(function ($inner) use ($stage, $field) {
                        $inner->where('status', $stage);

                        // A stage with no assignment slot (mapping) is always
                        // the whole team's; one with a slot only counts if empty.
                        if ($field) {
                            $inner->whereNull($field);
                        }
                    });
                }
            })
            ->with(['store', 'assignee', 'photographer', 'contentOwner', 'qaOwner'])
            ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByRaw('online_launch_date IS NULL, online_launch_date')
            ->get();
    }

    /**
     * A board of just one team's stages, grouped by stage so the photographer or
     * content team can see their workload at a glance.
     */
    public function queue(string $queue, Request $request, #[CurrentUser] User $user): View
    {
        abort_unless(isset(self::QUEUES[$queue]), 404);

        $config = self::QUEUES[$queue];

        $base = ProductRequest::query()->visibleTo($user)->whereIn('status', $config['stages']);

        // "Mine" lets a photographer filter to their own assignments without
        // losing sight of unassigned work by default.
        if ($request->boolean('mine')) {
            $base->where($config['owner_field'], $user->id);
        }

        if ($request->boolean('unassigned')) {
            $base->whereNull($config['owner_field']);
        }

        $requests = $base->with(['user', 'assignee', 'photographer', 'contentOwner', 'store'])
            // High priority first, then soonest launch; undated requests last.
            // CASE rather than MySQL's FIELD() so this also runs on SQLite.
            ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByRaw('online_launch_date IS NULL, online_launch_date')
            ->get();

        $columns = collect($config['stages'])
            ->mapWithKeys(fn ($stage) => [$stage => $requests->where('status', $stage)->values()]);

        return view('product-requests.queue', [
            'queueKey'   => $queue,
            'config'     => $config,
            'columns'    => $columns,
            'total'      => $requests->count(),
            'overdue'    => $requests->filter->isOverdue()->count(),
            'blocked'    => $requests->filter->isOnHold()->count(),
            'unassigned' => $requests->whereNull($config['owner_field'])->count(),
        ]);
    }

    // ── Create ───────────────────────────────────────────────────────────────

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'store_id'                  => 'required|exists:stores,id',
            'request_type'              => 'required|in:new_brand,existing_brand',
            'brand'                     => 'required|string|max:255',
            'category'                  => 'required|string|max:255',
            'sub_category'              => 'nullable|string|max:255',
            'department'                => 'nullable|string|max:255',
            'collection'                => 'nullable|string|max:255',
            'skus'                      => 'nullable|string',
            'sku_csv'                   => 'nullable|file|mimes:csv,txt|max:20480',
            'store_launch_date'         => 'required|date',
            'online_launch_date'        => 'required|date',
            'supplier_images_available' => 'required|boolean',
            'photoshoot_required'       => 'required|boolean',
            'use_ai_content'            => 'required|boolean',
            'priority'                  => 'required|in:high,medium,low',
            'notes'                     => 'nullable|string|max:5000',
            'reference_images'          => 'nullable|array|max:10',
            'reference_images.*'        => 'file|mimes:jpg,jpeg,png,pdf|max:10240',
            'content_sheet'             => 'nullable|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        // A user must not be able to file against a website they can't see.
        $store = Store::selectableFor($user)->firstWhere('id', (int) $data['store_id']);

        if (!$store) {
            return back()->withInput()->withErrors(['store_id' => 'You do not have access to that website.']);
        }

        $skus = $this->parseSkus($request);

        if (empty($skus)) {
            return back()->withInput()->withErrors(['skus' => 'Please enter at least one SKU or upload a CSV.']);
        }

        if (count($skus) > self::MAX_SKUS) {
            return back()->withInput()->withErrors([
                'skus' => 'A single request is limited to ' . number_format(self::MAX_SKUS) . ' SKUs. Please split this into multiple requests.',
            ]);
        }

        $productRequest = ProductRequest::create([
            'reference'                 => ProductRequest::nextReference(),
            'user_id'                   => $user->id,
            'store_id'                  => $store->id,
            'request_type'              => $data['request_type'],
            'brand'                     => $data['brand'],
            'category'                  => $data['category'],
            'sub_category'              => $data['sub_category'] ?? null,
            'department'                => $data['department'] ?? null,
            'collection'                => $data['collection'] ?? null,
            'status'                    => ProductRequest::SUBMITTED,
            'priority'                  => $data['priority'],
            'store_launch_date'         => $data['store_launch_date'],
            'online_launch_date'        => $data['online_launch_date'],
            'supplier_images_available' => (bool) $data['supplier_images_available'],
            'photoshoot_required'       => (bool) $data['photoshoot_required'],
            'use_ai_content'            => (bool) $data['use_ai_content'],
            'notes'                     => $data['notes'] ?? null,
            'validation_status'         => 'pending',
            'total_skus'                => count($skus),
        ]);

        $this->mapping->syncSkus($productRequest, $skus);
        $this->storeAttachments($request, $productRequest, $user);
        $this->storeAttachments($request, $productRequest, $user, 'content_sheet', ProductRequestAttachment::KIND_CONTENT);

        $this->workflow->log(
            request:     $productRequest,
            action:      'created',
            description: 'Product request created',
            actor:       $user,
            toStatus:    ProductRequest::SUBMITTED,
            remarks:     count($skus) . ' SKUs submitted',
        );

        ValidateProductRequestSkusJob::dispatch($productRequest->id, $user->id)->onQueue('bulkupload');

        return redirect()
            ->route('product-requests.show', $productRequest)
            ->with('success', "Request {$productRequest->reference} submitted. SKU validation is running.");
    }

    // ── Detail ───────────────────────────────────────────────────────────────

    public function show(ProductRequest $productRequest, #[CurrentUser] User $user): View
    {
        $this->authorizeView($productRequest, $user);

        $productRequest->load([
            'user', 'store', 'assignee', 'photographer', 'contentOwner', 'qaOwner', 'attachments.user',
        ]);

        $skus       = $productRequest->skus()->with('mappedBy')->orderBy('id')->paginate(50, ['*'], 'skus');
        $activities = $productRequest->activities()->with('user')->limit(20)->get();
        $teamPool   = User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'pcr_role']);

        return view('product-requests.show', [
            'request'    => $productRequest,
            'skus'       => $skus,
            'activities' => $activities,
            'teamPool'   => $teamPool,
        ]);
    }

    /** Polled while validation runs so the detail page fills in without a refresh. */
    public function status(ProductRequest $productRequest, #[CurrentUser] User $user): JsonResponse
    {
        $this->authorizeView($productRequest, $user);

        return response()->json([
            'status'            => $productRequest->status,
            'status_label'      => $productRequest->statusLabel(),
            'validation_status' => $productRequest->validation_status,
            'total_skus'        => $productRequest->total_skus,
            'mapped'            => $productRequest->mapped_skus,
            'pending'           => $productRequest->pending_skus,
            'not_mapped'        => $productRequest->not_mapped_skus,
            'progress'          => $productRequest->progressPercent(),
            'validated_at'      => $productRequest->validated_at?->toIso8601String(),
        ]);
    }

    public function update(Request $request, ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        abort_if($productRequest->isClosed(), 403, 'This request is closed and can no longer be edited.');

        $data = $request->validate([
            'brand'                     => 'required|string|max:255',
            'category'                  => 'required|string|max:255',
            'sub_category'              => 'nullable|string|max:255',
            'department'                => 'nullable|string|max:255',
            'collection'                => 'nullable|string|max:255',
            'store_launch_date'         => 'required|date',
            'online_launch_date'        => 'required|date',
            'supplier_images_available' => 'required|boolean',
            'photoshoot_required'       => 'required|boolean',
            'photoshoot_scheduled_at'   => 'nullable|date',
            'use_ai_content'            => 'required|boolean',
            'priority'                  => 'required|in:high,medium,low',
            'notes'                     => 'nullable|string|max:5000',
        ]);

        $productRequest->update($data);

        $this->workflow->log(
            request:     $productRequest,
            action:      'updated',
            description: 'Request details updated',
            actor:       $user,
        );

        return back()->with('success', 'Request updated.');
    }

    // ── SKUs ─────────────────────────────────────────────────────────────────

    /** Re-run validation on demand — the "Validate SKUs" button. */
    public function revalidate(ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        $productRequest->update(['validation_status' => 'pending']);

        $this->workflow->log(
            request:     $productRequest,
            action:      'sku_validation',
            description: 'SKU validation re-run requested',
            actor:       $user,
        );

        ValidateProductRequestSkusJob::dispatch($productRequest->id, $user->id)->onQueue('bulkupload');

        return back()->with('success', 'SKU validation started.');
    }

    /** Add SKUs to an existing request without re-submitting it. */
    public function addSkus(Request $request, ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        abort_if($productRequest->isClosed(), 403, 'This request is closed.');

        $request->validate([
            'skus'    => 'nullable|string',
            'sku_csv' => 'nullable|file|mimes:csv,txt|max:20480',
        ]);

        $incoming = $this->parseSkus($request);

        if (empty($incoming)) {
            return back()->withErrors(['skus' => 'Please enter at least one SKU or upload a CSV.']);
        }

        $merged = array_values(array_unique(array_merge(
            $productRequest->skus()->pluck('sku')->all(),
            $incoming,
        )));

        if (count($merged) > self::MAX_SKUS) {
            return back()->withErrors([
                'skus' => 'A single request is limited to ' . number_format(self::MAX_SKUS) . ' SKUs.',
            ]);
        }

        $added = count($merged) - $productRequest->skus()->count();

        $this->mapping->syncSkus($productRequest, $merged);

        $this->workflow->log(
            request:     $productRequest,
            action:      'sku_added',
            description: "{$added} SKU(s) added to the request",
            actor:       $user,
        );

        ValidateProductRequestSkusJob::dispatch($productRequest->id, $user->id)->onQueue('bulkupload');

        return back()->with('success', "{$added} SKU(s) added. Validation restarted.");
    }

    /**
     * Supply Chain records the mapping outcome. They do the mapping in Cegid on
     * their own side — there is no integration — so this entry is the signal
     * that releases a request from Waiting for Mapping.
     */
    public function updateMapping(Request $request, ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        $data = $request->validate([
            'sku_ids'        => 'nullable|array',
            'sku_ids.*'      => 'integer',
            'mapping_status' => 'required|in:' . implode(',', [
                ProductRequest::MAP_MAPPED,
                ProductRequest::MAP_PENDING,
                ProductRequest::MAP_NOT_MAPPED,
            ]),
            'mapping_note'   => 'nullable|string|max:255',
            'scope'          => 'nullable|in:selected,all',
        ]);

        $query = $productRequest->skus();

        if (($data['scope'] ?? 'selected') === 'selected') {
            if (empty($data['sku_ids'])) {
                return back()->withErrors(['sku_ids' => 'Select at least one SKU.']);
            }
            $query->whereIn('id', $data['sku_ids']);
        }

        $touched = $this->mapping->setMappingStatus(
            $query->get(),
            $data['mapping_status'],
            $user,
            $data['mapping_note'] ?? null,
        );

        $this->mapping->rollUp($productRequest);
        $productRequest->refresh();

        $label = ProductRequestSku::LABELS[$data['mapping_status']];

        $this->workflow->log(
            request:     $productRequest,
            action:      'mapping_updated',
            description: "{$touched} SKU(s) marked as {$label}",
            actor:       $user,
            remarks:     $data['mapping_note'] ?? null,
        );

        // Releases the request automatically when the last SKU lands.
        $this->workflow->reconcileMapping($productRequest, $user);

        return back()->with('success', "{$touched} SKU(s) updated to {$label}.");
    }

    /** Streamed so no export file ever lands on disk. */
    public function downloadSkus(ProductRequest $productRequest, Request $request, #[CurrentUser] User $user): StreamedResponse
    {
        $this->authorizeView($productRequest, $user);

        $filter = $request->get('filter', 'all');

        $query = $productRequest->skus()->orderBy('id');

        if (in_array($filter, [ProductRequest::MAP_MAPPED, ProductRequest::MAP_PENDING, ProductRequest::MAP_NOT_MAPPED], true)) {
            $query->where('mapping_status', $filter);
        }

        $filename = "{$productRequest->reference}-skus-{$filter}.csv";

        return response()->stream(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['SKU', 'Mapping Status', 'Recorded By', 'Recorded On', 'Mapping Note', 'In Shopify', 'Shopify Product ID', 'Product Name', 'Published', 'Last Checked']);

            $query->with('mappedBy')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->sku,
                        $row->label(),
                        $row->sourceLabel(),
                        $row->mapping_set_at?->format('Y-m-d H:i'),
                        $row->mapping_note,
                        $row->in_shopify ? 'Yes' : 'No',
                        $row->shopify_product_id,
                        $row->shopify_product_title,
                        $row->shopify_published === null ? '' : ($row->shopify_published ? 'TRUE' : 'FALSE'),
                        $row->last_checked_at?->format('Y-m-d H:i'),
                    ]);
                }
            });

            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ── Workflow ─────────────────────────────────────────────────────────────

    public function transition(Request $request, ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        $data = $request->validate([
            'to_status'               => 'required|string',
            'remarks'                 => 'nullable|string|max:1000',
            'photoshoot_scheduled_at' => 'nullable|date',
        ]);

        if (!empty($data['photoshoot_scheduled_at'])) {
            $productRequest->update(['photoshoot_scheduled_at' => $data['photoshoot_scheduled_at']]);
        }

        $moved = $this->workflow->transition(
            $productRequest,
            $data['to_status'],
            $user,
            $data['remarks'] ?? null,
        );

        if (!$moved) {
            return back()->withErrors([
                'to_status' => 'That status change is not allowed from ' . $productRequest->statusLabel()
                    . ($productRequest->isBlockedOnMapping() ? ' — all SKUs must be mapped first.' : '.'),
            ]);
        }

        return back()->with('success', 'Status updated to ' . $productRequest->fresh()->statusLabel() . '.');
    }

    public function assign(Request $request, ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        $data = $request->validate([
            'assigned_to'      => 'nullable|exists:users,id',
            'photographer_id'  => 'nullable|exists:users,id',
            'content_owner_id' => 'nullable|exists:users,id',
            'qa_owner_id'      => 'nullable|exists:users,id',
        ]);

        $before = $productRequest->only(array_keys(ProductRequest::ASSIGNMENT_ROLES));

        $productRequest->update($data);

        // Tell people they personally have work — a team-wide status notice
        // doesn't tell anyone that THEY are the one who has to act.
        $notified = [];

        foreach (ProductRequest::ASSIGNMENT_ROLES as $field => $roleLabel) {
            $assigneeId = $productRequest->{$field};

            if (!$assigneeId || $assigneeId == ($before[$field] ?? null)) {
                continue;
            }

            // No point pinging yourself about your own action.
            if ((int) $assigneeId === $user->id) {
                continue;
            }

            $assignee = User::find($assigneeId);

            if ($assignee?->is_active) {
                $assignee->notify(ProductRequestAssigned::forRequest($productRequest, $roleLabel, $user->name));
                $notified[] = "{$assignee->name} ({$roleLabel})";
            }
        }

        $this->workflow->log(
            request:     $productRequest,
            action:      'assigned',
            description: 'Team assignments updated',
            actor:       $user,
            remarks:     $notified ? 'Notified: ' . implode(', ', $notified) : null,
        );

        return back()->with('success', 'Team assignments updated.'
            . ($notified ? ' ' . count($notified) . ' person(s) notified.' : ''));
    }

    /**
     * Put your own name on the current stage. This is how unassigned work stops
     * being nobody's job — whoever picks it up says so, and everyone else can
     * see the ball is in their court.
     */
    public function claim(ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        if (!$productRequest->claimableBy($user)) {
            return back()->withErrors([
                'claim' => 'This stage is already assigned, or has no owner slot to claim.',
            ]);
        }

        $guide = $productRequest->currentGuide();
        $field = $guide['field'];

        $productRequest->update([$field => $user->id]);

        $this->workflow->log(
            request:     $productRequest,
            action:      'assigned',
            description: "{$user->name} took this task as {$guide['role']}",
            actor:       $user,
        );

        return back()->with('success', "You are now the {$guide['role']} on this request.");
    }

    /**
     * Flag that work has stalled — most often that the samples never reached
     * the studio, so the photographer physically cannot shoot. The request keeps
     * its stage; it is just visibly blocked, and everyone involved is told.
     */
    public function hold(Request $request, ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        abort_if($productRequest->isClosed(), 403, 'This request is closed.');

        $data = $request->validate([
            'hold_reason'       => 'required_without:hold_reason_other|nullable|string|max:255',
            'hold_reason_other' => 'nullable|string|max:255',
        ]);

        $reason = trim($data['hold_reason_other'] ?? '') ?: trim((string) ($data['hold_reason'] ?? ''));

        if ($reason === '') {
            return back()->withErrors(['hold_reason' => 'Please give a reason so the team knows what is blocking this.']);
        }

        $productRequest->update([
            'on_hold'     => true,
            'hold_reason' => $reason,
            'hold_since'  => now(),
            'hold_by'     => $user->id,
        ]);

        $this->workflow->log(
            request:     $productRequest,
            action:      'on_hold',
            description: 'Request put on hold',
            actor:       $user,
            remarks:     $reason,
        );

        $this->notifyHold($productRequest, $user);

        return back()->with('success', 'Request put on hold. Everyone involved has been notified.');
    }

    /** Clear the block and put the request back in play at the same stage. */
    public function resume(ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        if (!$productRequest->isOnHold()) {
            return back();
        }

        $was = $productRequest->hold_reason;

        $productRequest->update([
            'on_hold'     => false,
            'hold_reason' => null,
            'hold_since'  => null,
            'hold_by'     => null,
        ]);

        $this->workflow->log(
            request:     $productRequest,
            action:      'resumed',
            description: 'Request taken off hold',
            actor:       $user,
            remarks:     $was ? "Was blocked by: {$was}" : null,
        );

        $this->notifyHold($productRequest, $user);

        return back()->with('success', 'Request is back in progress.');
    }

    /**
     * Hand the current stage to someone else. The photographer who can't do a
     * shoot needs to pass it on without an admin rewiring the whole assignment
     * panel.
     */
    public function reassign(Request $request, ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        abort_if($productRequest->isClosed(), 403, 'This request is closed.');

        $data = $request->validate(['user_id' => 'required|exists:users,id']);

        $guide = $productRequest->currentGuide();
        $field = $guide['field'];

        if (!$field) {
            return back()->withErrors(['user_id' => 'This stage has no owner to hand over.']);
        }

        $newOwner = User::find($data['user_id']);

        if (!$newOwner?->is_active) {
            return back()->withErrors(['user_id' => 'That user is not active.']);
        }

        $productRequest->update([$field => $newOwner->id]);

        $this->workflow->log(
            request:     $productRequest,
            action:      'assigned',
            description: "Handed over to {$newOwner->name} as {$guide['role']}",
            actor:       $user,
        );

        if ($newOwner->id !== $user->id) {
            $newOwner->notify(ProductRequestAssigned::forRequest($productRequest, $guide['role'], $user->name));
        }

        return back()->with('success', "Handed over to {$newOwner->name}.");
    }

    private function notifyHold(ProductRequest $productRequest, User $actor): void
    {
        try {
            $recipients = $this->workflow->recipients($productRequest->fresh());

            if ($recipients->isNotEmpty()) {
                NotificationFacade::send(
                    $recipients,
                    ProductRequestHoldChanged::forRequest($productRequest->fresh(), $actor->name),
                );
            }
        } catch (\Throwable $e) {
            // Never let a notification failure undo the hold the user just set.
            report($e);
        }
    }

    public function comment(Request $request, ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        $data = $request->validate(['remarks' => 'required|string|max:2000']);

        $this->workflow->log(
            request:     $productRequest,
            action:      'comment',
            description: 'Comment added',
            actor:       $user,
            remarks:     $data['remarks'],
        );

        return back()->with('success', 'Comment added.');
    }

    public function cancel(Request $request, ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        abort_if($productRequest->isClosed(), 403, 'This request is already closed.');

        $data = $request->validate(['cancel_reason' => 'required|string|max:255']);

        $productRequest->update(['cancel_reason' => $data['cancel_reason']]);

        $this->workflow->transition(
            $productRequest,
            ProductRequest::CANCELLED,
            $user,
            $data['cancel_reason'],
        );

        return redirect()
            ->route('product-requests.show', $productRequest)
            ->with('success', 'Request cancelled.');
    }

    // ── Activity log ─────────────────────────────────────────────────────────

    public function activities(ProductRequest $productRequest, #[CurrentUser] User $user): View
    {
        $this->authorizeView($productRequest, $user);

        $activities = $productRequest->activities()->with('user')->paginate(50);

        return view('product-requests.activities', [
            'request'    => $productRequest,
            'activities' => $activities,
        ]);
    }

    // ── Attachments ──────────────────────────────────────────────────────────

    public function uploadAttachments(Request $request, ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        $isContent = $request->input('kind') === ProductRequestAttachment::KIND_CONTENT;

        $request->validate($isContent ? [
            'content_sheet' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ] : [
            'reference_images'   => 'required|array|max:10',
            'reference_images.*' => 'file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        $count = $isContent
            ? $this->storeAttachments($request, $productRequest, $user, 'content_sheet', ProductRequestAttachment::KIND_CONTENT)
            : $this->storeAttachments($request, $productRequest, $user);

        $this->workflow->log(
            request:     $productRequest,
            action:      'attachment_added',
            description: $isContent ? 'Content sheet uploaded' : "{$count} file(s) attached",
            actor:       $user,
        );

        return back()->with('success', $isContent ? 'Content sheet uploaded.' : "{$count} file(s) uploaded.");
    }

    public function downloadAttachment(ProductRequest $productRequest, ProductRequestAttachment $attachment, #[CurrentUser] User $user)
    {
        $this->authorizeView($productRequest, $user);

        abort_if($attachment->product_request_id !== $productRequest->id, 404);

        $path = storage_path("app/{$attachment->path}");
        abort_unless(is_file($path), 404, 'File no longer available.');

        return response()->download($path, $attachment->original_name);
    }

    public function destroyAttachment(ProductRequest $productRequest, ProductRequestAttachment $attachment, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        abort_if($attachment->product_request_id !== $productRequest->id, 404);

        $path = storage_path("app/{$attachment->path}");
        if (is_file($path)) {
            @unlink($path);
        }

        $name = $attachment->original_name;
        $attachment->delete();

        $this->workflow->log(
            request:     $productRequest,
            action:      'attachment_removed',
            description: "Attachment removed: {$name}",
            actor:       $user,
        );

        return back()->with('success', 'Attachment removed.');
    }

    // ── Notifications ────────────────────────────────────────────────────────

    public function notifications(#[CurrentUser] User $user): View
    {
        $notifications = $user->notifications()->paginate(30);

        return view('product-requests.notifications', compact('notifications'));
    }

    public function readNotifications(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        if ($id = $request->input('notification_id')) {
            $user->notifications()->where('id', $id)->update(['read_at' => now()]);
        } else {
            $user->unreadNotifications->markAsRead();
        }

        return back();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function authorizeView(ProductRequest $productRequest, User $user): void
    {
        $visible = ProductRequest::query()
            ->visibleTo($user)
            ->whereKey($productRequest->getKey())
            ->exists();

        abort_unless($visible, 403, 'You do not have access to this request.');
    }

    /**
     * @param  string  $field  form field holding the upload(s)
     * @param  string  $kind   ProductRequestAttachment::KIND_*
     */
    private function storeAttachments(
        Request $request,
        ProductRequest $productRequest,
        User $user,
        string $field = 'reference_images',
        string $kind = ProductRequestAttachment::KIND_REFERENCE,
    ): int {
        if (!$request->hasFile($field)) {
            return 0;
        }

        $dir      = "product-requests/{$productRequest->id}";
        $absolute = storage_path("app/{$dir}");

        if (!is_dir($absolute)) {
            mkdir($absolute, 0755, true);
        }

        $files = $request->file($field);
        $files = is_array($files) ? $files : [$files];   // single-file fields too
        $count = 0;

        foreach ($files as $file) {
            $name = uniqid("{$kind}_", true) . '.' . $file->getClientOriginalExtension();

            ProductRequestAttachment::create([
                'product_request_id' => $productRequest->id,
                'user_id'            => $user->id,
                'kind'               => $kind,
                'original_name'      => $file->getClientOriginalName(),
                'path'               => "{$dir}/{$name}",
                'mime'               => $file->getMimeType(),
                'size'               => $file->getSize(),
                'created_at'         => now(),
            ]);

            $file->move($absolute, $name);
            $count++;
        }

        return $count;
    }

    private function parseSkus(Request $request): array
    {
        $skus = [];

        if ($request->hasFile('sku_csv')) {
            $content = file_get_contents($request->file('sku_csv')->getRealPath());
            foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
                $sku = trim(str_getcsv($line)[0] ?? '');
                if ($sku && strtolower($sku) !== 'sku') {
                    $skus[] = $sku;
                }
            }
        }

        if ($request->filled('skus')) {
            foreach (preg_split('/[\r\n,]+/', $request->input('skus')) as $line) {
                if ($sku = trim($line)) {
                    $skus[] = $sku;
                }
            }
        }

        return array_values(array_unique(array_filter($skus)));
    }
}
