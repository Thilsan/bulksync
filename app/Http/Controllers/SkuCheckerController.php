<?php

namespace App\Http\Controllers;

use App\Models\SkuCheckItem;
use App\Models\SkuCheckSession;
use App\Models\Store;
use App\Services\ShopifyService;
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

        $items = $skuCheckSession->items()->orderBy('available')->orderBy('sku')->get();

        return view('sku-checker.show', compact('skuCheckSession', 'items'));
    }

    public function check(Request $request)
    {
        $request->validate([
            'skus'     => 'nullable|string|max:50000',
            'csv_file' => 'nullable|file|mimes:csv,txt|max:2048',
        ]);

        $skus = $this->parseSkus($request);

        if (empty($skus)) {
            return back()->withErrors(['skus' => 'Please enter at least one SKU or upload a CSV.']);
        }

        set_time_limit(300);

        $store   = Store::getActive();
        $shopify = new ShopifyService($store);

        $results = [];
        foreach ($skus as $sku) {
            $variants  = $shopify->findVariantsBySkuCached($sku);
            $results[] = [
                'sku'           => $sku,
                'available'     => !empty($variants),
                'product_title' => !empty($variants) ? $variants[0]['product_title'] : '',
                'product_id'    => !empty($variants) ? $variants[0]['product_id'] : '',
            ];
        }

        $available    = count(array_filter($results, fn ($r) => $r['available']));
        $notAvailable = count($results) - $available;

        // Save to history
        $session = SkuCheckSession::create([
            'user_id'             => auth()->id(),
            'store_id'            => $store?->id,
            'total_skus'          => count($results),
            'available_count'     => $available,
            'not_available_count' => $notAvailable,
        ]);

        $chunks = array_chunk($results, 500);
        foreach ($chunks as $chunk) {
            $rows = array_map(fn ($r) => [
                'sku_check_session_id' => $session->id,
                'sku'                  => $r['sku'],
                'available'            => $r['available'],
                'product_title'        => $r['product_title'],
                'product_id'           => $r['product_id'],
                'created_at'           => now(),
                'updated_at'           => now(),
            ], $chunk);
            SkuCheckItem::insert($rows);
        }

        return view('sku-checker.index', compact('results', 'available', 'notAvailable', 'session'));
    }

    public function download(SkuCheckSession $skuCheckSession)
    {
        abort_if($skuCheckSession->user_id !== auth()->id(), 403);

        $items = $skuCheckSession->items()->orderBy('available')->orderBy('sku')->get();

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="sku-check-' . $skuCheckSession->id . '.csv"',
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
                if ($sku) {
                    $skus[] = $sku;
                }
            }
        }

        return array_values(array_unique(array_filter($skus)));
    }
}
