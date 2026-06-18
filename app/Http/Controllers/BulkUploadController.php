<?php

namespace App\Http\Controllers;

use App\Jobs\ScanOneDriveFolderJob;
use App\Models\UploadItem;
use App\Models\UploadSession;
use App\Services\ImageProcessingService;
use App\Services\ShopifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BulkUploadController extends Controller
{
    public function __construct(
        private ImageProcessingService $imageService,
    ) {}

    // ── Views ──────────────────────────────────────────────────────────────

    public function create(): View
    {
        $activeStore        = \App\Models\Store::getActive();
        $shopifyConfigured  = $activeStore && $activeStore->shopify_access_token;
        $onedriveConfigured = !empty(auth()->user()->onedrive_access_token);
        $dimensionPresets   = $this->imageService->dimensionPresets();

        return view('upload.create', compact('shopifyConfigured', 'onedriveConfigured', 'dimensionPresets'));
    }

    public function history(): View
    {
        $user = auth()->user();

        if ($user->is_super_admin) {
            $sessions = UploadSession::latest()->paginate(20);
        } else {
            $sessions = UploadSession::where('user_id', $user->id)->latest()->paginate(20);
        }

        return view('upload.history', compact('sessions'));
    }

    public function show(UploadSession $session): View
    {
        $user = auth()->user();

        if (!$user->is_super_admin && $session->user_id !== $user->id) {
            abort(403);
        }

        return view('upload.show', compact('session'));
    }

    public function destroy(UploadSession $session): RedirectResponse
    {
        $user = auth()->user();

        if (!$user->is_super_admin && $session->user_id !== $user->id) {
            abort(403);
        }

        $session->items()->delete();
        $session->delete();

        return redirect()->route('upload.history')
            ->with('success', 'Session "' . $session->name . '" deleted.');
    }

    public function syncVariantImages(UploadSession $session): JsonResponse
    {
        $user = auth()->user();

        if (!$user->is_super_admin && $session->user_id !== $user->id) {
            abort(403);
        }

        $shopify = new ShopifyService();
        $results = [];

        // One representative item per variant using groupBy (more reliable than unique()).
        $firstPerVariant = UploadItem::where('upload_session_id', $session->id)
            ->where('status', 'uploaded')
            ->orderBy('id')
            ->get()
            ->groupBy('variant_id')
            ->map(fn ($group) => $group->first())
            ->filter(fn ($item) => $item->variant_id && $item->product_id);

        // Cache product images so we only call Shopify once per product.
        $productImages = [];

        foreach ($firstPerVariant as $item) {
            // Prefer the image_id we stored at upload time.
            $imageId = $item->shopify_image_id ?: null;

            // If it's missing, look up by alt text — we upload every image with alt = SKU.
            if (!$imageId) {
                if (!isset($productImages[$item->product_id])) {
                    $productImages[$item->product_id] = $shopify->getProductImages($item->product_id);
                }
                foreach ($productImages[$item->product_id] as $img) {
                    if (($img['alt'] ?? '') === $item->sku_detected) {
                        $imageId = (string) $img['id'];
                        break;
                    }
                }
            }

            if (!$imageId) {
                $results[] = [
                    'sku'        => $item->sku_detected,
                    'variant_id' => $item->variant_id,
                    'status'     => 'skip',
                    'error'      => 'no image found in Shopify for SKU ' . $item->sku_detected,
                ];
                continue;
            }

            try {
                $shopify->setVariantImage($item->variant_id, $imageId);
                $results[] = [
                    'sku'        => $item->sku_detected,
                    'variant_id' => $item->variant_id,
                    'image_id'   => $imageId,
                    'status'     => 'ok',
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'sku'        => $item->sku_detected,
                    'variant_id' => $item->variant_id,
                    'image_id'   => $imageId,
                    'status'     => 'error',
                    'error'      => $e->getMessage(),
                ];
            }
        }

        $okCount   = count(array_filter($results, fn ($r) => $r['status'] === 'ok'));
        $errCount  = count(array_filter($results, fn ($r) => $r['status'] === 'error'));
        $skipCount = count(array_filter($results, fn ($r) => $r['status'] === 'skip'));

        return response()->json([
            'synced'  => $okCount,
            'errors'  => $errCount,
            'skipped' => $skipCount,
            'results' => $results,
        ]);
    }

    // ── Actions ────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'               => ['nullable', 'string', 'max:255'],
            'onedrive_link'      => ['required', 'url'],
            'image_width'        => ['nullable', 'integer', 'min:100', 'max:5000'],
            'image_height'       => ['nullable', 'integer', 'min:100', 'max:5000'],
            'duplicate_handling' => ['required', 'in:skip,replace,add'],
        ]);

        $hasSize = filled($validated['image_width']) && filled($validated['image_height']);

        $session = UploadSession::create([
            'user_id'            => auth()->id(),
            'name'               => $validated['name'] ?: 'Upload ' . now()->format('Y-m-d H:i'),
            'onedrive_link'      => $validated['onedrive_link'],
            'image_width'        => $hasSize ? (int) $validated['image_width']  : null,
            'image_height'       => $hasSize ? (int) $validated['image_height'] : null,
            'image_size'         => $hasSize ? $validated['image_width'] . 'x' . $validated['image_height'] : 'original',
            'duplicate_handling' => $validated['duplicate_handling'],
            'status'             => 'processing',
            'scan_status'        => 'pending',
        ]);

        // Pre-warm Shopify SKU cache (synchronous here — takes ~60s for large stores)
        // Dispatched as part of the scan job chain
        ScanOneDriveFolderJob::dispatch($session->id)
            ->onQueue('bulkupload');

        return redirect()->route('upload.show', $session)
            ->with('info', 'Scan started! Watching for images in your OneDrive folder…');
    }

    /**
     * Status endpoint — called every few seconds by the progress page.
     * Returns JSON progress without driving any processing.
     */
    public function status(Request $request, UploadSession $session): JsonResponse
    {
        $user = auth()->user();

        if (!$user->is_super_admin && $session->user_id !== $user->id) {
            abort(403);
        }

        // Efficient single-query count breakdown
        $counts = UploadItem::where('upload_session_id', $session->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'uploaded'   THEN 1 ELSE 0 END) as uploaded,
                SUM(CASE WHEN status = 'failed'     THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'skipped'    THEN 1 ELSE 0 END) as skipped,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'matched'    THEN 1 ELSE 0 END) as matched,
                SUM(CASE WHEN status = 'pending'    THEN 1 ELSE 0 END) as pending
            ")
            ->first();

        $done     = ($counts->uploaded ?? 0) + ($counts->failed ?? 0) + ($counts->skipped ?? 0);
        $total    = max($session->total_files, $counts->total ?? 0);
        $progress = $total > 0 ? (int) min(100, round(($done / $total) * 100)) : 0;

        $perPage   = 50;
        $page      = max(1, (int) $request->get('page', 1));

        $paginator = UploadItem::where('upload_session_id', $session->id)
            ->orderByRaw("CASE status WHEN 'processing' THEN 1 WHEN 'uploaded' THEN 2 WHEN 'failed' THEN 3 WHEN 'skipped' THEN 4 WHEN 'matched' THEN 5 ELSE 6 END")
            ->orderByDesc('updated_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = $paginator->getCollection()->map(fn (UploadItem $i) => [
            'id'            => $i->id,
            'filename'      => $i->filename,
            'sku'           => $i->sku_detected,
            'product_title' => $i->product_title,
            'status'        => $i->status,
            'status_label'  => $i->statusLabel(),
            'status_color'  => $i->statusColor(),
            'original_kb'   => $i->original_size_kb,
            'processed_kb'  => $i->processed_size_kb,
            'error'         => $i->error_message,
        ]);

        return response()->json([
            'session' => [
                'id'           => $session->id,
                'status'       => $session->status,
                'scan_status'  => $session->scan_status,
                'is_finished'  => in_array($session->status, ['completed', 'failed']),
                'progress'     => $progress,
                'total'        => $total,
                'scanned'      => $session->scanned_files,
                'uploaded'     => (int) ($counts->uploaded   ?? 0),
                'failed'       => (int) ($counts->failed     ?? 0),
                'skipped'      => (int) ($counts->skipped    ?? 0),
                'processing'   => (int) ($counts->processing ?? 0),
                'pending'      => (int) ($counts->pending    ?? 0),
            ],
            'items'      => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Warm the Shopify SKU cache on demand (called from the upload form).
     */
    public function warmCache(): JsonResponse
    {
        $count = app(ShopifyService::class)->warmSkuCache();

        return response()->json(['ok' => true, 'count' => $count]);
    }
}
