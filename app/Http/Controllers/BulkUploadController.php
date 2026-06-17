<?php

namespace App\Http\Controllers;

use App\Jobs\ScanOneDriveFolderJob;
use App\Models\Setting;
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
        $shopifyConfigured  = (bool) Setting::get('shopify_domain') && (bool) Setting::get('shopify_access_token');
        $onedriveConfigured = (bool) Setting::get('onedrive_tenant_id');
        $dimensionPresets   = $this->imageService->dimensionPresets();

        return view('upload.create', compact('shopifyConfigured', 'onedriveConfigured', 'dimensionPresets'));
    }

    public function history(): View
    {
        $sessions = UploadSession::latest()->paginate(20);

        return view('upload.history', compact('sessions'));
    }

    public function show(UploadSession $session): View
    {
        return view('upload.show', compact('session'));
    }

    // ── Actions ────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'          => ['nullable', 'string', 'max:255'],
            'onedrive_link' => ['required', 'url'],
            'image_width'   => ['nullable', 'integer', 'min:100', 'max:5000'],
            'image_height'  => ['nullable', 'integer', 'min:100', 'max:5000'],
        ]);

        $hasSize = filled($validated['image_width']) && filled($validated['image_height']);

        $session = UploadSession::create([
            'name'          => $validated['name'] ?: 'Upload ' . now()->format('Y-m-d H:i'),
            'onedrive_link' => $validated['onedrive_link'],
            'image_width'   => $hasSize ? (int) $validated['image_width']  : null,
            'image_height'  => $hasSize ? (int) $validated['image_height'] : null,
            'image_size'    => $hasSize ? $validated['image_width'] . 'x' . $validated['image_height'] : 'original',
            'status'        => 'processing',
            'scan_status'   => 'pending',
        ]);

        // Pre-warm Shopify SKU cache (synchronous here — takes ~60s for large stores)
        // Dispatched as part of the scan job chain
        ScanOneDriveFolderJob::dispatch($session->id)
            ->onQueue('bulk-upload');

        return redirect()->route('upload.show', $session)
            ->with('info', 'Scan started! Watching for images in your OneDrive folder…');
    }

    /**
     * Status endpoint — called every few seconds by the progress page.
     * Returns JSON progress without driving any processing.
     */
    public function status(UploadSession $session): JsonResponse
    {
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

        // Latest 100 items for the live table
        $items = UploadItem::where('upload_session_id', $session->id)
            ->orderByRaw("CASE status WHEN 'processing' THEN 1 WHEN 'uploaded' THEN 2 WHEN 'failed' THEN 3 WHEN 'skipped' THEN 4 WHEN 'matched' THEN 5 ELSE 6 END")
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (UploadItem $i) => [
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
            'items' => $items,
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
