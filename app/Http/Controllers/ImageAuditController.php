<?php

namespace App\Http\Controllers;

use App\Jobs\RunImageAuditJob;
use App\Models\ImageAuditItem;
use App\Models\ImageAuditSession;
use App\Models\Store;
use Illuminate\Http\Request;

class ImageAuditController extends Controller
{
    public function index()
    {
        $sessions = ImageAuditSession::where('user_id', auth()->id())
            ->with('store')
            ->latest()
            ->paginate(20);

        return view('image-audit.index', compact('sessions'));
    }

    public function start()
    {
        $store = Store::getActive();

        $session = ImageAuditSession::create([
            'user_id'  => auth()->id(),
            'store_id' => $store?->id,
            'status'   => 'pending',
        ]);

        RunImageAuditJob::dispatch($session->id)->onQueue('bulkupload');

        return redirect()->route('image-audit.show', $session)
            ->with('success', 'Image audit started. This may take a few minutes.');
    }

    public function show(ImageAuditSession $imageAuditSession)
    {
        abort_if($imageAuditSession->user_id !== auth()->id(), 403);

        return view('image-audit.show', compact('imageAuditSession'));
    }

    public function status(ImageAuditSession $imageAuditSession)
    {
        abort_if($imageAuditSession->user_id !== auth()->id(), 403);

        return response()->json([
            'status'           => $imageAuditSession->status,
            'total_products'   => $imageAuditSession->total_products,
            'scanned_products' => $imageAuditSession->scanned_products,
            'progress'         => $imageAuditSession->progressPercent(),
            'total_skus'       => $imageAuditSession->total_skus,
            'with_images'      => $imageAuditSession->with_images,
            'without_images'   => $imageAuditSession->without_images,
        ]);
    }

    public function items(ImageAuditSession $imageAuditSession, Request $request)
    {
        abort_if($imageAuditSession->user_id !== auth()->id(), 403);

        $filter = $request->get('filter', 'all');
        $search = $request->get('search', '');

        $query = $imageAuditSession->items();

        if ($filter === 'with') {
            $query->where('has_image', true);
        } elseif ($filter === 'without') {
            $query->where('has_image', false);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                  ->orWhere('product_title', 'like', "%{$search}%");
            });
        }

        $items = $query->orderBy('has_image')->orderBy('sku')->paginate(100)->withQueryString();

        return response()->json([
            'items'       => $items->items(),
            'total'       => $items->total(),
            'current_page'=> $items->currentPage(),
            'last_page'   => $items->lastPage(),
        ]);
    }

    public function download(ImageAuditSession $imageAuditSession, Request $request)
    {
        abort_if($imageAuditSession->user_id !== auth()->id(), 403);

        $filter = $request->get('filter', 'all');

        $query = $imageAuditSession->items();
        if ($filter === 'with')    $query->where('has_image', true);
        if ($filter === 'without') $query->where('has_image', false);
        $items = $query->orderBy('has_image')->orderBy('sku')->get();

        $filename = "image-audit-{$imageAuditSession->id}-{$filter}.csv";

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($items) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['SKU', 'Product Title', 'Product ID', 'Image Count', 'Has Image']);
            foreach ($items as $item) {
                fputcsv($handle, [
                    $item->sku,
                    $item->product_title,
                    $item->product_id,
                    $item->image_count,
                    $item->has_image ? 'Yes' : 'No',
                ]);
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function destroy(ImageAuditSession $imageAuditSession)
    {
        abort_if($imageAuditSession->user_id !== auth()->id(), 403);
        $imageAuditSession->delete();
        return redirect()->route('image-audit.index')->with('success', 'Audit deleted.');
    }
}
