<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\ShopifyService;
use Illuminate\Http\Request;

class SkuCheckerController extends Controller
{
    public function index()
    {
        return view('sku-checker.index');
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

        session(['sku_check_results' => $results]);

        $available    = count(array_filter($results, fn ($r) => $r['available']));
        $notAvailable = count($results) - $available;

        return view('sku-checker.index', compact('results', 'available', 'notAvailable'));
    }

    public function download()
    {
        $results = session('sku_check_results', []);

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="sku-check-results.csv"',
        ];

        $callback = function () use ($results) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['SKU', 'Status', 'Product Title', 'Product ID']);
            foreach ($results as $row) {
                fputcsv($handle, [
                    $row['sku'],
                    $row['available'] ? 'Available' : 'Not Available',
                    $row['product_title'],
                    $row['product_id'],
                ]);
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
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
