<?php

namespace App\Http\Controllers;

use App\Jobs\ValidateProductRequestSkusJob;
use App\Models\ProductRequest;
use App\Models\ProductRequestActivity;
use App\Models\ProductRequestAttachment;
use App\Models\ProductRequestSku;
use App\Models\Store;
use App\Models\User;
use App\Services\ProductRequestWorkflow;
use App\Services\SkuMappingService;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductRequestController extends Controller implements HasMiddleware
{
    /** A single request can't carry more SKUs than this — keeps one bad CSV from flooding the table. */
    public const MAX_SKUS = 5000;

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

        return view('product-requests.index', compact(
            'stats', 'breakdown', 'recent', 'deadlines', 'topBrands', 'activity'
        ));
    }

    /** Full, filterable request list — the "View Requests" screen. */
    public function list(Request $request, #[CurrentUser] User $user): View
    {
        $query = ProductRequest::query()->visibleTo($user)->with(['user', 'assignee']);

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

        return view('product-requests.list', compact('requests', 'brands'));
    }

    // ── Create ───────────────────────────────────────────────────────────────

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
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
            'priority'                  => 'required|in:high,medium,low',
            'notes'                     => 'nullable|string|max:5000',
            'reference_images'          => 'nullable|array|max:10',
            'reference_images.*'        => 'file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

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
            'store_id'                  => Store::getActive($user->id)?->id,
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
            'notes'                     => $data['notes'] ?? null,
            'validation_status'         => 'pending',
            'total_skus'                => count($skus),
        ]);

        $this->mapping->syncSkus($productRequest, $skus);
        $this->storeAttachments($request, $productRequest, $user);

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

        $productRequest->update($data);

        $this->workflow->log(
            request:     $productRequest,
            action:      'assigned',
            description: 'Team assignments updated',
            actor:       $user,
        );

        return back()->with('success', 'Team assignments updated.');
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

        $request->validate([
            'reference_images'   => 'required|array|max:10',
            'reference_images.*' => 'file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        $count = $this->storeAttachments($request, $productRequest, $user);

        $this->workflow->log(
            request:     $productRequest,
            action:      'attachment_added',
            description: "{$count} file(s) attached",
            actor:       $user,
        );

        return back()->with('success', "{$count} file(s) uploaded.");
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

    private function storeAttachments(Request $request, ProductRequest $productRequest, User $user): int
    {
        if (!$request->hasFile('reference_images')) {
            return 0;
        }

        $dir = "product-requests/{$productRequest->id}";
        $absolute = storage_path("app/{$dir}");

        if (!is_dir($absolute)) {
            mkdir($absolute, 0755, true);
        }

        $count = 0;

        foreach ($request->file('reference_images') as $file) {
            $name = uniqid('ref_', true) . '.' . $file->getClientOriginalExtension();

            ProductRequestAttachment::create([
                'product_request_id' => $productRequest->id,
                'user_id'            => $user->id,
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
