<?php

namespace App\Http\Controllers;

use App\Jobs\RunSkuCheckJob;
use App\Models\SkuCheckItem;
use App\Models\SkuCheckSession;
use App\Models\Store;
use Illuminate\Http\Request;

class SkuCheckerController extends Controller
{
    public function index()
    {
        return view('sku-checker.index');
    }

    public function history()
    {
        $sessions = SkuCheckSession::where('user_id', auth()->id())
            ->with('store')
            ->latest()
            ->paginate(20);

        return view('sku-checker.history', compact('sessions'));
    }

    public function show(SkuCheckSession $skuCheckSession)
    {
        abort_if($skuCheckSession->user_id !== auth()->id(), 403);
        return view('sku-checker.show', compact('skuCheckSession'));
    }

    public function status(SkuCheckSession $skuCheckSession)
    {
        abort_if($skuCheckSession->user_id !== auth()->id(), 403);

        return response()->json([
            'status'        => $skuCheckSession->status,
            'total_skus'    => $skuCheckSession->total_skus,
            'scanned_skus'  => $skuCheckSession->scanned_skus,
            'progress'      => $skuCheckSession->progressPercent(),
            'available'     => $skuCheckSession->available_count,
            'not_available' => $skuCheckSession->not_available_count,
        ]);
    }

    public function items(SkuCheckSession $skuCheckSession, Request $request)
    {
        abort_if($skuCheckSession->user_id !== auth()->id(), 403);

        $filter = $request->get('filter', 'all');
        $search = $request->get('search', '');

        $query = $skuCheckSession->items();

        if ($filter === 'available')     $query->where('available', true);
        if ($filter === 'not_available') $query->where('available', false);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                  ->orWhere('product_title', 'like', "%{$search}%");
            });
        }

        $items = $query->orderBy('available')->orderBy('sku')->paginate(100)->withQueryString();

        return response()->json([
            'items'        => $items->items(),
            'total'        => $items->total(),
            'current_page' => $items->currentPage(),
            'last_page'    => $items->lastPage(),
        ]);
    }

    public function check(Request $request)
    {
        $request->validate([
            'skus'     => 'nullable|string',
            'csv_file' => 'nullable|file|mimes:csv,txt|max:20480', // 20MB
        ]);

        $skus = $this->parseSkus($request);

        if (empty($skus)) {
            return back()->withErrors(['skus' => 'Please enter at least one SKU or upload a CSV.']);
        }

        $store   = Store::getActive();
        $session = SkuCheckSession::create([
            'user_id'    => auth()->id(),
            'store_id'   => $store?->id,
            'status'     => 'pending',
            'total_skus' => count($skus),
            'raw_skus'   => implode("\n", $skus),
        ]);

        RunSkuCheckJob::dispatch($session->id)->onQueue('bulkupload');

        return redirect()->route('sku-checker.show', $session);
    }

    public function download(SkuCheckSession $skuCheckSession, Request $request)
    {
        abort_if($skuCheckSession->user_id !== auth()->id(), 403);

        $filter = $request->get('filter', 'all');
        $query  = $skuCheckSession->items();
        if ($filter === 'available')     $query->where('available', true);
        if ($filter === 'not_available') $query->where('available', false);
        $items = $query->orderBy('available')->orderBy('sku')->get();

        $filename = "sku-check-{$skuCheckSession->id}-{$filter}.csv";
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($items) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['SKU', 'Status', 'Product Title', 'Product ID']);
            foreach ($items as $item) {
                fputcsv($handle, [
                    $item->sku,
                    $item->available ? 'Available' : 'Not Available',
                    $item->product_title,
                    $item->product_id,
                ]);
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function destroy(SkuCheckSession $skuCheckSession)
    {
        abort_if($skuCheckSession->user_id !== auth()->id(), 403);
        $skuCheckSession->delete();
        return back()->with('success', 'Check session deleted.');
    }

    private function parseSkus(Request $request): array
    {
        $skus = [];

        if ($request->hasFile('csv_file')) {
            $content = file_get_contents($request->file('csv_file')->getRealPath());
            $lines   = preg_split('/\r\n|\r|\n/', $content);
            foreach ($lines as $line) {
                $parts = str_getcsv($line);
                $sku   = trim($parts[0] ?? '');
                if ($sku && strtolower($sku) !== 'sku') {
                    $skus[] = $sku;
                }
            }
        }

        if ($request->filled('skus')) {
            $lines = preg_split('/[\r\n,]+/', $request->input('skus'));
            foreach ($lines as $line) {
                $sku = trim($line);
                if ($sku) $skus[] = $sku;
            }
        }

        return array_values(array_unique(array_filter($skus)));
    }
}
