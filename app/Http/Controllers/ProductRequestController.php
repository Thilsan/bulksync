<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiContentJob;
use App\Jobs\ValidateProductRequestSkusJob;
use App\Models\AiContentSession;
use App\Models\ProductRequest;
use App\Models\ProductRequestActivity;
use App\Models\ProductRequestAttachment;
use App\Models\ProductRequestSku;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ProductRequestAssigned;
use App\Notifications\ProductRequestCommented;
use App\Notifications\ProductRequestHoldChanged;
use App\Models\ProductRequestDraftProduct;
use App\Services\ProductRequestDraftBuilder;
use App\Services\ProductRequestDraftCsv;
use App\Services\ProductRequestDraftPusher;
use App\Services\ProductRequestSheetSyncService;
use App\Services\ProductRequestWorkflow;
use App\Services\SkuMappingService;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Validation\Rule;
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
            'owner_fields'   => ['photographer_id'],
            'owner_label'    => 'Photoshoot Coordinator',
            'stages'         => [
                ProductRequest::WAITING_IMAGES,
                ProductRequest::PHOTOSHOOT_SCHEDULED,
                ProductRequest::PHOTOSHOOT_COMPLETED,
            ],
        ],
        'content' => [
            'title'          => 'Content Creation',
            'description'    => 'Requests generating product copy.',
            // Both stages belong to the E-Commerce owner now — editing came with
            // the photoshoot and content is part of running the request.
            'owner_field'    => 'assigned_to',
            'owner_fields'   => ['assigned_to'],
            'owner_label'    => 'Owner',
            'stages'         => [
                ProductRequest::IMAGE_EDITING,
                ProductRequest::AI_CONTENT,
            ],
        ],
    ];

    public function __construct(
        private readonly ProductRequestWorkflow $workflow,
        private readonly SkuMappingService $mapping,
        private readonly ProductRequestSheetSyncService $sheetSync,
        private readonly ProductRequestDraftBuilder $draftBuilder,
        private readonly ProductRequestDraftPusher $draftPusher,
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
        // The dashboard answers "how is MY work going", so it counts what is on
        // this person's desk — not every request they are allowed to open.
        $base = fn () => ProductRequest::query()->onMyDesk($user);

        $stats = [
            'total'             => $base()->count(),
            'pending'           => $base()->where('status', ProductRequest::SUBMITTED)->count(),
            'waiting_mapping'   => $base()->where('status', ProductRequest::WAITING_MAPPING)->count(),
            'in_progress'       => $base()->inProgress()->count(),
            'waiting_photoshoot'=> $base()->whereIn('status', [ProductRequest::WAITING_IMAGES, ProductRequest::PHOTOSHOOT_SCHEDULED])->count(),
            'qa_review'         => $base()->where('status', ProductRequest::QA_REVIEW)->count(),
            'on_hold'           => $base()->onHold()->count(),
            // Publishing closes a request, so live and done are the same number.
            // Legacy "completed" rows are counted here too.
            'published'         => $base()->whereIn('status', [ProductRequest::PUBLISHED, ProductRequest::COMPLETED])->count(),
        ];

        $breakdown = $base()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $recent = $base()->with(['user', 'currentAssignments.user'])->latest()->limit(8)->get();

        $deadlines = $base()
            ->whereNotIn('status', ProductRequest::CLOSED_STATUSES)
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

        // Websites the requester can raise a request against. Who does the work
        // is decided by the category, not chosen on the form.
        $stores        = Store::selectableFor($user);
        $activeStoreId = Store::getActive($user->id)?->id;

        return view('product-requests.index', compact(
            'stats', 'breakdown', 'recent', 'deadlines', 'topBrands', 'activity', 'stores', 'activeStoreId'
        ) + $this->categoryStaffing());
    }

    /** Full, filterable request list — the "View Requests" screen. */
    public function list(Request $request, #[CurrentUser] User $user): View
    {
        // All four owner relations are eager-loaded: the "Waiting On" column
        // resolves whichever one the current stage belongs to, per row.
        // store is loaded too: the list shows which website each request is for,
        // which is the only thing separating two rows raised from one sheet row.
        $query = ProductRequest::query()->onMyDesk($user)
            ->with(['user', 'store', 'currentAssignments.user']);

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
                  ->orWhere('name', 'like', $term)
                  ->orWhere('brand', 'like', $term)
                  ->orWhere('category', 'like', $term);
            });
        }

        // Sheet order, so the list reads the same way as the tracking sheet the
        // team works from. Requests raised by hand have no sheet number and sit
        // after the numbered ones, newest first. The id tiebreak keeps the two or
        // three requests one sheet row makes (BS / PG / SN) next to each other.
        $requests = $query
            ->orderByRaw('sheet_request_no IS NULL')
            ->orderBy('sheet_request_no')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        $brands = ProductRequest::query()->onMyDesk($user)
            ->distinct()->orderBy('brand')->pluck('brand');

        // The New Request slide-over lives on this page too, and needs the
        // websites this user may file against.
        $stores        = Store::selectableFor($user);
        $activeStoreId = Store::getActive($user->id)?->id;
        $teamPool      = User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'pcr_role']);

        return view('product-requests.list', compact('requests', 'brands', 'stores', 'activeStoreId', 'teamPool') + $this->categoryStaffing());
    }

    /**
     * Apply one change to several requests at once. At 50+ open requests,
     * re-assigning a departed photographer one page at a time is the kind of
     * chore that quietly pushes people back to spreadsheets.
     *
     * Anything not permitted on a given request is skipped and counted rather
     * than failing the whole batch — a partial result the user can see beats an
     * all-or-nothing error.
     */
    public function bulk(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'action'    => 'required|in:assign,priority,status',
            'ids'       => 'required|array|min:1',
            'ids.*'     => 'integer',
            'field'     => 'required_if:action,assign|nullable|in:' . implode(',', array_keys(ProductRequest::ASSIGNMENT_ROLES)),
            'user_id'   => 'required_if:action,assign|nullable|exists:users,id',
            'priority'  => 'required_if:action,priority|nullable|in:high,medium,low',
            'to_status' => 'required_if:action,status|nullable|string',
            'due_date'  => 'nullable|date',
            'remarks'   => 'nullable|string|max:255',
        ]);

        // Scoped the same way as the list it was posted from, so a bulk action
        // can never reach a request that was not on screen.
        $requests = ProductRequest::query()
            ->onMyDesk($user)
            ->whereIn('id', $data['ids'])
            ->get();

        $done = 0;
        $skipped = 0;

        foreach ($requests as $productRequest) {
            $changed = match ($data['action']) {
                'assign'   => $this->workflow->assignRole(
                                  request: $productRequest,
                                  field:   $data['field'],
                                  userId:  (int) $data['user_id'],
                                  actor:   $user,
                                  dueDate: $data['due_date'] ?? null,
                              ),
                'priority' => $this->bulkPriority($productRequest, $data['priority'], $user),
                'status'   => $this->workflow->transition($productRequest, $data['to_status'], $user, $data['remarks'] ?? null),
                default    => false,
            };

            $changed ? $done++ : $skipped++;
        }

        $message = "{$done} " . str('request')->plural($done) . ' updated.';

        if ($skipped > 0) {
            $message .= " {$skipped} skipped — the change did not apply at their current stage.";
        }

        return back()->with($done > 0 ? 'success' : 'warning', $message);
    }

    /**
     * Pulls new rows from the shared tracking sheet and creates matching
     * requests — the UI equivalent of `php artisan product-requests:sync-sheet
     * --commit`. Gated to super admins: unlike everything else on this
     * controller, one click here can create hundreds of real requests at once.
     */
    public function syncSheet(#[CurrentUser] User $user): RedirectResponse
    {
        abort_unless($user->is_super_admin, 403, 'Only a super admin can sync from the tracking sheet.');

        set_time_limit(300); // several large worksheets to read on a slow connection

        try {
            $result = $this->sheetSync->run(commit: true);
        } catch (\Throwable $e) {
            Log::error('Product request sheet sync failed: ' . $e->getMessage());
            return back()->with('warning', 'Sync failed: ' . $e->getMessage());
        }

        $message = "{$result['created']} request(s) created.";

        if ($result['backfilled'] > 0) {
            $message .= " {$result['backfilled']} existing request(s) updated from the sheet.";
        }

        $flagged = $result['unmatched_department'] + $result['unmatched_store'] + $result['unmatched_skus'] + $result['errors'];
        if ($flagged > 0) {
            $message .= " {$flagged} row(s) need manual review (unmatched department/store/SKUs or errors).";
        }

        return back()->with($result['created'] + $result['backfilled'] > 0 ? 'success' : 'warning', $message);
    }

    private function bulkPriority(ProductRequest $productRequest, string $priority, User $actor): bool
    {
        if ($productRequest->isClosed() || $productRequest->priority === $priority) {
            return false;
        }

        $was = $productRequest->priorityLabel();
        $productRequest->update(['priority' => $priority]);

        $this->workflow->log(
            request:     $productRequest,
            action:      'updated',
            description: "Priority changed from {$was} to " . $productRequest->priorityLabel(),
            actor:       $actor,
        );

        return true;
    }

    /** Everything currently sitting with this user, in any of the four roles. */
    public function myTasks(Request $request, #[CurrentUser] User $user): View
    {
        $query = ProductRequest::query()
            ->assignedTo($user)
            ->with(['user', 'store', 'assignments.user', 'currentAssignments.user']);

        // Closed work is hidden by default — this is a to-do list, not history.
        if (!$request->boolean('include_closed')) {
            $query->whereNotIn('status', ProductRequest::CLOSED_STATUSES);
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
                ->whereIn('status', ProductRequest::CLOSED_STATUSES)->count(),
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

                        // A stage with no assignment slot is always the whole
                        // team's; one with a slot only counts if nobody holds it.
                        if ($field) {
                            $inner->whereDoesntHave('currentAssignments',
                                fn ($a) => $a->where('role', $field));
                        }
                    });
                }
            })
            ->with(['store', 'currentAssignments.user'])
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
        $ownerFields = $config['owner_fields'] ?? [$config['owner_field']];

        if ($request->boolean('mine')) {
            $base->whereHas('currentAssignments', fn ($q) => $q
                ->where('user_id', $user->id)
                ->whereIn('role', $ownerFields));
        }

        if ($request->boolean('unassigned')) {
            // No live assignment for any role this board covers.
            $base->whereDoesntHave('currentAssignments', fn ($q) => $q->whereIn('role', $ownerFields));
        }

        $requests = $base->with(['user', 'store', 'currentAssignments.user'])
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
            'unassigned' => $requests->filter(fn ($r) => $r->currentGuide()['owner'] === null)->count(),
        ]);
    }

    /**
     * Who a request will land on, for the new-request form.
     *
     * The requester picks a category, not a person — so the form has to be able
     * to show them who that means before they submit.
     *
     * @return array{categoryOwnerNames: array<string, string>, categoryBrandManagerNames: array<string, string>, photoshootCoordinator: ?string}
     */
    private function categoryStaffing(): array
    {
        return [
            'categoryOwnerNames'        => collect(User::categoryOwners())->map->name->all(),
            // First brand manager per category — the one who takes the role.
            'categoryBrandManagerNames' => collect(User::categoryBrandManagers())
                ->map(fn ($people) => $people->first()?->name)
                ->filter()
                ->all(),
            'photoshootCoordinator'     => User::photoshootCoordinator()?->name,
        ];
    }

    // ── Create ───────────────────────────────────────────────────────────────

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        if ($problem = $this->rejectedUpload($request, ['content_sheet', 'sku_csv'])) {
            return back()->withInput()->withErrors(['content_sheet' => $problem]);
        }

        $maxKb = min(10240, ProductRequestAttachment::maxUploadKb());

        $data = $request->validate([
            'store_id'                  => 'required|exists:stores,id',
            'name'                      => 'nullable|string|max:255',
            'request_type'              => 'required|in:new_brand,existing_brand',
            'brand'                     => 'required|string|max:255',
            'category'                  => ['required', Rule::in(ProductRequest::CATEGORIES)],
            'skus'                      => 'nullable|string',
            'sku_csv'                   => 'nullable|file|mimes:csv,txt|max:20480',
            'online_launch_date'        => 'required|date',
            'image_source'              => 'required|in:' . implode(',', array_keys(ProductRequest::selectableImageSources())),
            // Only asked for when the supplier sent them; a photoshoot produces
            // its own images and has nowhere to point at yet.
            'images_location'           => 'nullable|required_if:image_source,' . ProductRequest::IMG_SUPPLIER . '|in:' . implode(',', array_keys(ProductRequest::IMAGE_LOCATIONS)),
            'images_url'                => 'nullable|required_if:images_location,' . ProductRequest::IMAGES_AT_URL . '|url|max:2048',
            'use_ai_content'            => 'required|boolean',
            'priority'                  => 'required|in:high,medium,low',
            'assignments'               => 'nullable|array|max:' . count(ProductRequest::ASSIGNMENT_ROLES),
            'assignments.*.role'        => 'nullable|in:' . implode(',', array_keys(ProductRequest::ASSIGNMENT_ROLES)),
            'assignments.*.user_id'     => 'nullable|exists:users,id',
            'assignments.*.due_date'    => 'nullable|date',
            'notes'                     => 'nullable|string|max:5000',
            'content_sheet'             => 'nullable|file|mimes:csv,txt,xlsx,xls|max:' . $maxKb,
        ]);

        // A user must not be able to file against a website they can't see.
        $store = Store::selectableFor($user)->firstWhere('id', (int) $data['store_id']);

        if (!$store) {
            return back()->withInput()->withErrors(['store_id' => 'You do not have access to that website.']);
        }

        // Role/person pairs, collapsed to one entry per role.
        $assignments = [];

        foreach ($data['assignments'] ?? [] as $row) {
            $role   = $row['role'] ?? null;
            $userId = $row['user_id'] ?? null;

            if (!$role || !$userId) {
                continue;   // a half-filled row is just an unused slot
            }

            if (isset($assignments[$role])) {
                return back()->withInput()->withErrors([
                    'assignments' => 'Each role can only be given to one person. "'
                        . ProductRequest::ASSIGNMENT_ROLES[$role] . '" is listed twice.',
                ]);
            }

            $assignments[$role] = [
                'user_id'  => (int) $userId,
                'due_date' => $row['due_date'] ?? null,
            ];
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
            'name'                      => $data['name'] ?? null,
            'user_id'                   => $user->id,
            'store_id'                  => $store->id,
            'request_type'              => $data['request_type'],
            'brand'                     => $data['brand'],
            'category'                  => $data['category'],
            'status'                    => ProductRequest::SUBMITTED,
            'priority'                  => $data['priority'],
            'online_launch_date'        => $data['online_launch_date'],
            'image_source'              => $data['image_source'],
            'images_location'           => $data['image_source'] === ProductRequest::IMG_SUPPLIER ? ($data['images_location'] ?? null) : null,
            'images_url'                => ($data['images_location'] ?? null) === ProductRequest::IMAGES_AT_URL ? ($data['images_url'] ?? null) : null,
            // Kept in step with image_source so historic rows and any code still
            // reading these booleans stay correct.
            'supplier_images_available' => $data['image_source'] === ProductRequest::IMG_SUPPLIER,
            'photoshoot_required'       => $data['image_source'] === ProductRequest::IMG_PHOTOSHOOT,
            // A shoot enters the Photoshoot Room the moment the request is raised,
            // waiting for a date rather than waiting to be noticed.
            'photoshoot_status'         => $data['image_source'] === ProductRequest::IMG_PHOTOSHOOT
                ? ProductRequest::SHOOT_PENDING
                : null,
            'use_ai_content'            => (bool) $data['use_ai_content'],
            'notes'                     => $data['notes'] ?? null,
            'validation_status'         => 'pending',
            'total_skus'                => count($skus),
        ]);

        $this->mapping->syncSkus($productRequest, $skus);

        // Reference images are attached from the request page, not at submission.
        $this->storeAttachments($request, $productRequest, $user, 'content_sheet', ProductRequestAttachment::KIND_CONTENT);

        $this->workflow->log(
            request:     $productRequest,
            action:      'created',
            description: 'Product request created',
            actor:       $user,
            toStatus:    ProductRequest::SUBMITTED,
            remarks:     count($skus) . ' SKUs submitted',
        );

        // Apply each role the requester filled in. assignRole writes the owner
        // column, the brief, the audit entry and the notification together.
        $assigned = 0;

        foreach ($assignments as $field => $detail) {
            if ($this->workflow->assignRole(
                request:  $productRequest,
                field:    $field,
                userId:   $detail['user_id'],
                actor:    $user,
                dueDate:  $detail['due_date'],
            )) {
                $assigned++;
            }
        }

        // Whatever the requester left blank is staffed from the category — its
        // owner takes the request, and a shoot goes to the photoshoot coordinator.
        $staffed = $this->workflow->staffFromCategory($productRequest, $user);
        $assigned += count($staffed);

        ValidateProductRequestSkusJob::dispatch($productRequest->id, $user->id)->onQueue('bulkupload');

        $names = collect($staffed)->unique('id')->pluck('name')->join(', ', ' and ');

        return redirect()
            ->route('product-requests.show', $productRequest)
            ->with('success', "Request {$productRequest->reference} submitted. SKU validation is running."
                . ($names !== '' ? " {$productRequest->category} goes to {$names}." : '')
                . ($assigned > 0 ? " {$assigned} " . str('assignment')->plural($assigned) . ' made.' : ''));
    }

    // ── Detail ───────────────────────────────────────────────────────────────

    public function show(ProductRequest $productRequest, #[CurrentUser] User $user): View
    {
        $this->authorizeView($productRequest, $user);

        $productRequest->load([
            'user', 'store', 'attachments.user', 'aiContentSession', 'assignments.user', 'assignments.assignedBy', 'currentAssignments.user',
        ]);

        $skus       = $productRequest->skus()->with('mappedBy')->orderBy('id')->paginate(50, ['*'], 'skus');
        $activities = $productRequest->activities()->with('user')->limit(20)->get();
        $teamPool   = User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'pcr_role']);

        // The draft builder only applies where SKUs are not resolved through
        // Cegid — everywhere else an unmatched SKU is Supply Chain's to answer.
        $drafts     = $productRequest->requiresMapping()
            ? collect()
            : $productRequest->draftProducts()->with('variants')->orderBy('id')->get();

        return view('product-requests.show', [
            'request'      => $productRequest,
            'skus'         => $skus,
            'activities'   => $activities,
            'teamPool'     => $teamPool,
            'drafts'       => $drafts,
            // The push asks which website to create the products in, rather than
            // assuming the one the request was raised against.
            'pushStores'   => $productRequest->requiresMapping() ? collect() : Store::selectableFor($user),
            'missingSkus'  => $productRequest->requiresMapping()
                ? 0
                : $productRequest->skus()->where('in_shopify', false)->count(),
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
            // AI content generation runs on the queue; the show page polls this
            // so the progress bar moves without a manual reload.
            'ai_content'        => ($ai = $productRequest->aiContentSession) ? [
                'status'          => $ai->status,
                'status_label'    => $ai->statusLabel(),
                'total_items'     => $ai->total_items,
                'processed_items' => $ai->processed_items,
                'progress'        => $ai->progressPercent(),
                'error_message'   => $ai->error_message,
            ] : null,
        ]);
    }

    public function update(Request $request, ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        abort_if($productRequest->isClosed(), 403, 'This request is closed and can no longer be edited.');

        $data = $request->validate([
            'name'                      => 'nullable|string|max:255',
            'brand'                     => 'required|string|max:255',
            // The request's own category stays valid even if it predates the list,
            // so editing anything else on an older request doesn't force a change.
            'category'                  => ['required', Rule::in($productRequest->categoryOptions())],
            'online_launch_date'        => 'required|date',
            'image_source'              => 'required|in:' . implode(',', array_keys(ProductRequest::IMAGE_SOURCES)),
            'images_location'           => 'nullable|in:' . implode(',', array_keys(ProductRequest::IMAGE_LOCATIONS)),
            'images_url'                => 'nullable|required_if:images_location,' . ProductRequest::IMAGES_AT_URL . '|url|max:2048',
            'photoshoot_scheduled_at'   => 'nullable|date',
            'use_ai_content'            => 'required|boolean',
            'priority'                  => 'required|in:high,medium,low',
            'notes'                     => 'nullable|string|max:5000',
        ]);

        $isSupplier  = $data['image_source'] === ProductRequest::IMG_SUPPLIER;
        $needsShoot  = $data['image_source'] === ProductRequest::IMG_PHOTOSHOOT;

        $productRequest->update($data + [
            'supplier_images_available' => $isSupplier,
            'photoshoot_required'       => $needsShoot,
            // Deciding to shoot puts the request in the Photoshoot Room; deciding
            // not to takes it back out, and its old booking with it.
            'photoshoot_status'         => $needsShoot
                ? ($productRequest->photoshoot_status ?? ProductRequest::SHOOT_PENDING)
                : null,
            // Switching away from supplier images clears a location that no
            // longer describes anything.
            'images_location'           => $isSupplier ? ($data['images_location'] ?? null) : null,
            'images_url'                => $isSupplier && ($data['images_location'] ?? null) === ProductRequest::IMAGES_AT_URL
                                              ? ($data['images_url'] ?? null) : null,
        ]);

        $this->workflow->log(
            request:     $productRequest,
            action:      'updated',
            description: 'Request details updated',
            actor:       $user,
        );

        return back()->with('success', 'Request updated.');
    }

    /**
     * Delete a request outright. Super admins only.
     *
     * Cancelling is what everyone else does — it keeps the history, which is the
     * point of an audit trail. This is for requests that should never have
     * existed: a duplicate, a test, a mistake. It takes the SKUs, activity,
     * assignments and attachments with it, files included, and clears the bell
     * entries so nothing links to a request that is gone.
     */
    public function destroy(ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless($user->is_super_admin, 403, 'Only a super admin can delete a request.');

        $reference = $productRequest->reference;

        // The rows cascade; the files on disk do not.
        foreach ($productRequest->attachments as $attachment) {
            $path = storage_path("app/{$attachment->path}");

            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir(storage_path("app/product-requests/{$productRequest->id}"));

        // Notifications carry the request id in their payload rather than a
        // foreign key, so nothing else would ever clean them up.
        DB::table('notifications')
            ->where('data', 'like', '%"request_id":' . $productRequest->id . ',%')
            ->delete();

        $productRequest->delete();

        Log::warning("ProductRequest {$reference} deleted by {$user->email}.");

        return redirect()
            ->route('product-requests.list')
            ->with('success', "Request {$reference} and everything attached to it has been deleted.");
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

        if ($problem = $this->rejectedUpload($request, ['sku_csv'])) {
            return back()->withErrors(['sku_csv' => $problem]);
        }

        $request->validate([
            'skus'    => 'nullable|string',
            'sku_csv' => 'nullable|file|mimes:csv,txt|max:' . min(20480, ProductRequestAttachment::maxUploadKb()),
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

        $wasMapped = (int) $productRequest->mapped_skus;
        $wasStatus = $productRequest->status;

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

        // A request that carried on with part of its SKUs has people waiting on
        // the balance. They are not looking at the SKUs tab, so tell them.
        if ($productRequest->mapped_skus > $wasMapped
            && !in_array($wasStatus, [ProductRequest::SUBMITTED, ProductRequest::WAITING_MAPPING], true)) {
            $this->workflow->announceBalance($productRequest, $productRequest->mapped_skus - $wasMapped);
        }

        return back()->with('success', "{$touched} SKU(s) updated to {$label}."
            . ($productRequest->hasSkuBalance()
                ? " {$productRequest->balanceSkus()} still outstanding ({$productRequest->skuCompletionPercent()}% ready)."
                : ''));
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

    // ── Shopify drafts ───────────────────────────────────────────────────────

    /**
     * Pulls the product information for this request's not-in-Shopify SKUs off
     * the tracking sheet and stages them as draft products for review.
     */
    public function buildDrafts(ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        set_time_limit(300);   // a category tab can be thousands of rows

        try {
            $result = $this->draftBuilder->build($productRequest, $user);
        } catch (\Throwable $e) {
            Log::error("Draft build failed for {$productRequest->reference}: " . $e->getMessage());
            return back()->with('warning', $e->getMessage());
        }

        if ($result['built'] === 0 && $result['skipped_existing'] === 0) {
            return back()->with('warning', 'Nothing to build — every SKU on this request is already in Shopify, or the sheet has no rows for the ones that are not.');
        }

        $message = "{$result['built']} draft product(s) built from {$result['variants']} SKU(s).";

        if ($result['skipped_existing'] > 0) {
            $message .= " {$result['skipped_existing']} left alone — already pushed, or corrected by hand.";
        }

        if ($missing = $result['missing_from_sheet']) {
            $shown    = array_slice($missing, 0, 10);
            $message .= ' ' . count($missing) . ' SKU(s) have no row on the sheet: ' . implode(', ', $shown)
                . (count($missing) > count($shown) ? '…' : '') . '.';
        }

        // Which sheet column became which field, so a wrong guess is visible now
        // rather than after fourteen products land in Shopify without prices.
        $columns = $result['columns'];

        if ($columns['missing']) {
            $message .= ' No column found for: ' . implode(', ', $columns['missing'])
                . '. The tab also has ' . (count($columns['ignored']) ?: 'no')
                . ' unused column(s)'
                . ($columns['ignored'] ? ': ' . implode(', ', array_slice($columns['ignored'], 0, 12)) : '') . '.';
        }

        if ($columns['loose']) {
            $message .= ' Matched loosely — check these: ' . implode(', ', $columns['loose']) . '.';
        }

        return back()->with('success', $message);
    }

    /** The staged drafts in Shopify's product import CSV format. Streamed. */
    public function downloadDrafts(ProductRequest $productRequest, #[CurrentUser] User $user, ProductRequestDraftCsv $csv): StreamedResponse
    {
        $this->authorizeView($productRequest, $user);

        $filename = "{$productRequest->reference}-shopify-import.csv";

        return response()->stream(function () use ($productRequest, $csv) {
            $out = fopen('php://output', 'w');
            $csv->write($productRequest, $out);
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** Corrections made on the review screen — anything the sheet left blank. */
    public function updateDraft(Request $request, ProductRequest $productRequest, ProductRequestDraftProduct $draft, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);
        abort_unless($draft->product_request_id === $productRequest->id, 404);

        if ($draft->isPushed()) {
            return back()->with('warning', 'This product is already in Shopify — edit it there.');
        }

        $data = $request->validate([
            'title'                    => ['required', 'string', 'max:255'],
            'body_html'                => ['nullable', 'string', 'max:65535'],
            'vendor'                   => ['nullable', 'string', 'max:255'],
            'product_type'             => ['nullable', 'string', 'max:255'],
            'tags'                     => ['nullable', 'string', 'max:1000'],
            'image_src'                => ['nullable', 'url', 'max:2048'],
            'variants'                 => ['nullable', 'array'],
            'variants.*.id'            => ['required', 'integer'],
            'variants.*.price'         => ['nullable', 'numeric', 'min:0'],
            'variants.*.compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.barcode'       => ['nullable', 'string', 'max:255'],
            'variants.*.option1_value' => ['nullable', 'string', 'max:255'],
            'variants.*.option2_value' => ['nullable', 'string', 'max:255'],
            'variants.*.option3_value' => ['nullable', 'string', 'max:255'],
            'variants.*.inventory_qty' => ['nullable', 'integer', 'min:0'],
        ]);

        // Stamped so rebuilding from the sheet keeps this rather than replacing it.
        $draft->update(collect($data)->except('variants')->all() + ['edited_at' => now()]);

        foreach ($data['variants'] ?? [] as $row) {
            // Scoped to this draft so a crafted id cannot edit another request's.
            $draft->variants()->whereKey($row['id'])->first()?->update(
                collect($row)->except('id')->all()
            );
        }

        return back()->with('success', "\"{$draft->title}\" updated.");
    }

    public function destroyDraft(ProductRequest $productRequest, ProductRequestDraftProduct $draft, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);
        abort_unless($draft->product_request_id === $productRequest->id, 404);

        if ($draft->isPushed()) {
            return back()->with('warning', 'This product is already in Shopify — deleting the draft here would not remove it.');
        }

        $title = $draft->title;
        $draft->delete();

        return back()->with('success', "\"{$title}\" removed from the drafts.");
    }

    /**
     * Creates the reviewed drafts in the chosen Shopify store. Always as drafts —
     * going live stays a decision someone makes in Shopify.
     */
    public function pushDrafts(Request $request, ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        $data = $request->validate([
            'store_id'    => ['required', 'exists:stores,id'],
            'draft_ids'   => ['nullable', 'array'],
            'draft_ids.*' => ['integer'],
        ]);

        // The push writes to a real storefront, so the target has to be a website
        // this person actually works in — not just any id they can type.
        $store = Store::selectableFor($user)->firstWhere('id', (int) $data['store_id']);

        if (!$store) {
            return back()->withErrors(['store_id' => 'You do not have access to that website.']);
        }

        set_time_limit(600);   // Shopify fetches every image URL server-side

        $result = $this->draftPusher->push($productRequest, $store, $user, $data['draft_ids'] ?? null);

        $message = "{$result['pushed']} product(s) created as drafts in {$store->name}.";

        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} skipped — already pushed, or still missing a title or price.";
        }

        if ($result['failed'] > 0) {
            $message .= " {$result['failed']} failed — the reason is on each row.";
        }

        return back()->with($result['pushed'] > 0 ? 'success' : 'warning', $message);
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
                    . ($productRequest->isBlockedOnMapping() ? ' — no SKU has been mapped yet.' : '.'),
            ]);
        }

        return back()->with('success', 'Status updated to ' . $productRequest->fresh()->statusLabel() . '.');
    }

    /**
     * Carry on with the SKUs that are mapped, and leave the rest as the balance.
     *
     * Ten mappable SKUs used to wait on ten that Supply Chain had not reached, so
     * a whole launch moved at the speed of its slowest line. The mapped part goes
     * ahead; the hourly SKU check keeps watching the balance and tells whoever
     * holds the request as soon as more of it lands.
     */
    public function continueWithMapped(ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        if (!$productRequest->canContinueWithMapped()) {
            return back()->withErrors([
                'to_status' => $productRequest->mapped_skus < 1
                    ? 'No SKU is mapped yet, so there is nothing to carry on with.'
                    : 'This request is not waiting on a partial mapping.',
            ]);
        }

        $mapped  = (int) $productRequest->mapped_skus;
        $balance = $productRequest->balanceSkus();

        $this->workflow->transition(
            $productRequest,
            ProductRequest::SKU_VERIFIED,
            $user,
            "Continuing with {$mapped} of {$productRequest->total_skus} SKUs ({$productRequest->skuCompletionPercent()}%) — "
                . "{$balance} still with Supply Chain.",
        );

        return back()->with('success', "Carrying on with {$mapped} SKUs. The remaining {$balance} stay on the SKUs tab and "
            . 'you will be told as soon as Supply Chain maps them.');
    }

    public function assign(Request $request, ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        $fields = array_keys(ProductRequest::ASSIGNMENT_ROLES);

        $data = $request->validate(
            collect($fields)->mapWithKeys(fn ($f) => [$f => 'nullable|exists:users,id'])->all()
        );

        $changed = 0;

        foreach ($fields as $field) {
            if ($this->workflow->assignRole(
                request: $productRequest,
                field:   $field,
                userId:  isset($data[$field]) ? (int) $data[$field] : null,
                actor:   $user,
                // No per-person deadlines any more — a request has one date, the
                // launch. A date already on an old assignment is left alone
                // rather than wiped by the next save.
                dueDate: $productRequest->assignmentFor($field)?->due_date?->toDateString(),
            )) {
                $changed++;
            }
        }

        return back()->with($changed > 0 ? 'success' : 'warning', $changed > 0
            ? "{$changed} " . str('assignment')->plural($changed) . ' updated.'
            : 'Nothing changed.');
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

        // notify: false — you don't need telling about your own action.
        $this->workflow->assignRole(
            request:     $productRequest,
            field:       $guide['field'],
            userId:      $user->id,
            actor:       $user,
            notify:      false,
            description: "{$user->name} took this task as {$guide['role']}",
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

        // Keeps whatever brief and deadline the role already carried.
        $this->workflow->assignRole(
            request:     $productRequest,
            field:       $field,
            userId:      $newOwner->id,
            actor:       $user,
            description: "Handed over to {$newOwner->name} as {$guide['role']}",
        );

        return back()->with('success', "Handed over to {$newOwner->name}.");
    }

    /**
     * Tell everyone on the request about a comment. @mentions are singled out so
     * a direct question reads differently from general chatter.
     *
     * @return int  people notified
     */
    private function notifyComment(ProductRequest $productRequest, string $body, User $actor): int
    {
        try {
            // @Name / @name.surname — matched against active users on this request
            // and anyone holding a workflow role.
            preg_match_all('/@([\p{L}][\p{L}0-9._-]{1,60})/u', $body, $matches);
            $handles = collect($matches[1])->map(fn ($h) => strtolower(str_replace(['.', '_', '-'], ' ', $h)));

            $recipients = $this->workflow->recipients($productRequest)
                ->reject(fn (User $u) => $u->id === $actor->id);   // don't ping yourself

            if ($recipients->isEmpty()) {
                return 0;
            }

            foreach ($recipients as $recipient) {
                $name      = strtolower($recipient->name);
                $firstName = strtolower(strtok($recipient->name, ' '));

                $mentioned = $handles->contains(fn ($h) => $h === $name || $h === $firstName);

                $recipient->notify(
                    ProductRequestCommented::forRequest($productRequest, $body, $actor->name, $mentioned)
                );
            }

            return $recipients->count();
        } catch (\Throwable $e) {
            // A failed notification must never lose the comment itself.
            report($e);
            return 0;
        }
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

    /**
     * Kick off the AI Content Generator for this request's SKUs.
     *
     * Generation reads product images from Shopify, so only SKUs already live
     * there can be processed — for a brand-new product that means after upload.
     * Rather than failing on the rest, we generate for what exists and say
     * plainly how many were skipped and why.
     */
    public function generateAiContent(ProductRequest $productRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeView($productRequest, $user);

        if (!$productRequest->use_ai_content) {
            return back()->withErrors(['ai' => 'This request is set to use content supplied by the brand team.']);
        }

        abort_if($productRequest->isClosed(), 403, 'This request is closed.');

        // Ordered so generation runs in the order the SKUs were submitted, which
        // is what someone watching the progress bar expects to see.
        $eligible = $productRequest->skus()->where('in_shopify', true)->orderBy('id')->pluck('sku');
        $skipped  = $productRequest->total_skus - $eligible->count();

        if ($eligible->isEmpty()) {
            return back()->withErrors([
                'ai' => 'None of these SKUs exist in Shopify yet. AI content is generated from the live product images, '
                      . 'so upload the products first (or re-run SKU validation if they are already there).',
            ]);
        }

        // Uppercased and deduped exactly as the AI Content Generator screen does,
        // so a request raised from here behaves identically.
        $skus = $eligible->map(fn ($sku) => strtoupper(trim($sku)))->filter()->unique()->values();

        $session = AiContentSession::create([
            'user_id'    => $user->id,
            'store_id'   => $productRequest->store_id,
            'input_type' => 'sku_list',
            'sku_raw'    => $skus->implode("\n"),
            // The job reads skus_json — sku_raw is only what the user typed. Without
            // this the job found no SKUs, did nothing, and reported itself ready:
            // "Status Ready, Progress 0/1" with no error anywhere.
            'skus_json'  => json_encode($skus->all()),
            'status'     => 'pending',
            'total_items'=> $skus->count(),
        ]);

        $productRequest->update(['ai_content_session_id' => $session->id]);

        GenerateAiContentJob::dispatch($session->id)->onQueue('bulkupload');

        $this->workflow->log(
            request:     $productRequest,
            action:      'ai_content',
            description: "AI content generation started for {$eligible->count()} SKU(s)",
            actor:       $user,
            remarks:     $skipped > 0 ? "{$skipped} SKU(s) skipped — not in Shopify yet" : null,
        );

        return back()->with('success', $skipped > 0
            ? "AI content generation started for {$eligible->count()} SKU(s). {$skipped} skipped — not in Shopify yet."
            : "AI content generation started for {$eligible->count()} SKU(s).");
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

        $told = $this->notifyComment($productRequest, $data['remarks'], $user);

        return back()->with('success', $told > 0
            ? "Comment added. {$told} " . str('person')->plural($told) . ' notified.'
            : 'Comment added.');
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

        if ($problem = $this->rejectedUpload($request, ['reference_images', 'content_sheet'])) {
            return back()->withErrors(['reference_images' => $problem]);
        }

        $maxKb     = min(10240, ProductRequestAttachment::maxUploadKb());
        $isContent = $request->input('kind') === ProductRequestAttachment::KIND_CONTENT;

        $request->validate($isContent ? [
            'content_sheet' => 'required|file|mimes:csv,txt,xlsx,xls|max:' . $maxKb,
        ] : [
            'reference_images'   => 'required|array|max:10',
            'reference_images.*' => 'file|mimes:jpg,jpeg,png,pdf|max:' . $maxKb,
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

    /**
     * The reader's notifications, their own work first.
     *
     * Defaults to "mine": the messages that name this person. Team-wide status
     * changes and the copies that go to followers are still here under
     * "everything", but they are not what the page opens on.
     */
    public function notifications(Request $request, #[CurrentUser] User $user): View
    {
        $scope = $request->query('scope') === 'all' ? 'all' : 'mine';

        $notifications = ($scope === 'all' ? $user->notifications() : $user->ownNotifications())
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('product-requests.notifications', [
            'notifications' => $notifications,
            'scope'         => $scope,
            'mineUnread'    => $user->unreadOwnNotifications()->count(),
            'allUnread'     => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Unread count and the newest few, for the top bar to poll.
     *
     * Notifications arrive from queued jobs and hourly checks, so without this
     * the bell only updates when somebody happens to load a page.
     */
    public function notificationFeed(#[CurrentUser] User $user): JsonResponse
    {
        $unread = $user->unreadOwnNotifications()->latest()->limit(8)->get();

        return response()->json([
            'unread' => $unread->count(),
            'items'  => $unread->map(fn ($note) => [
                'id'      => $note->id,
                'kind'    => $note->data['kind'] ?? null,
                'title'   => $note->data['reference'] ?? 'Request',
                'body'    => trim(($note->data['brand'] ?? '') . ' · ' . ($note->data['status_label'] ?? ''), ' ·'),
                'url'     => !empty($note->data['request_id'])
                    ? route('product-requests.show', $note->data['request_id'])
                    : route('product-requests.notifications'),
            ])->values(),
        ]);
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

    /**
     * PHP silently discards a file larger than upload_max_filesize: it never
     * reaches hasFile(), a `nullable` rule passes, and the user is told nothing.
     * Catch that and say so, rather than saving a record with a missing file.
     *
     * @param  string[]  $fields
     */
    private function rejectedUpload(Request $request, array $fields): ?string
    {
        $limit = ProductRequestAttachment::maxUploadLabel();

        foreach ($fields as $field) {
            $files = $request->file($field);

            if ($files === null) {
                continue;
            }

            foreach (is_array($files) ? $files : [$files] as $file) {
                if ($file && !$file->isValid()) {
                    $reason = match ($file->getError()) {
                        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                            "is larger than this server accepts ({$limit} max). Please split or compress it.",
                        UPLOAD_ERR_PARTIAL   => 'was only partially uploaded. Please try again.',
                        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                            'could not be saved on the server. Please contact an administrator.',
                        default              => 'could not be uploaded. Please try again.',
                    };

                    return "The file \"{$file->getClientOriginalName()}\" {$reason}";
                }
            }
        }

        return null;
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
