<?php

namespace App\Http\Controllers;

use App\Jobs\RunCsvCompareJob;
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

        $filePath = storage_path("app/sku-checks/{$skuCheckSession->id}.csv");
        abort_unless(file_exists($filePath), 404, 'Result file not found.');

        $filter   = $request->get('filter', 'all');
        $filename = "sku-check-{$skuCheckSession->id}-{$filter}.csv";

        if ($filter === 'all') {
            return response()->download($filePath, $filename, ['Content-Type' => 'text/csv']);
        }

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($filePath, $filter) {
            $out = fopen('php://output', 'w');
            $in  = fopen($filePath, 'r');
            fputcsv($out, fgetcsv($in)); // header row
            while (($row = fgetcsv($in)) !== false) {
                $status = strtolower($row[1] ?? '');
                if ($filter === 'available' && $status === 'available') {
                    fputcsv($out, $row);
                } elseif ($filter === 'not_available' && $status === 'not available') {
                    fputcsv($out, $row);
                }
            }
            fclose($in);
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function csvCompare(Request $request)
    {
        $request->validate([
            'my_csv'      => 'required|file|mimes:csv,txt|max:20480',
            'shopify_csv' => 'required|file|mimes:csv,txt|max:102400',
        ]);

        $skus = $this->parseCsvFile($request->file('my_csv'));

        if (empty($skus)) {
            return back()->withErrors(['my_csv' => 'No valid SKUs found in the uploaded CSV.']);
        }

        $store   = Store::getActive();
        $session = SkuCheckSession::create([
            'user_id'    => auth()->id(),
            'store_id'   => $store?->id,
            'status'     => 'pending',
            'total_skus' => count($skus),
            'raw_skus'   => implode("\n", $skus),
        ]);

        $dir = storage_path('app/sku-checks');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $request->file('shopify_csv')->move($dir, "shopify_{$session->id}.csv");

        RunCsvCompareJob::dispatch($session->id)->onQueue('bulkupload');

        return redirect()->route('sku-checker.show', $session);
    }

    public function destroy(SkuCheckSession $skuCheckSession)
    {
        abort_if($skuCheckSession->user_id !== auth()->id(), 403);
        $filePath = storage_path("app/sku-checks/{$skuCheckSession->id}.csv");
        if (file_exists($filePath)) {
            unlink($filePath);
        }
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

    private function parseCsvFile($file): array
    {
        $skus    = [];
        $content = file_get_contents($file->getRealPath());
        $lines   = preg_split('/\r\n|\r|\n/', $content);
        foreach ($lines as $line) {
            $parts = str_getcsv($line);
            $sku   = trim($parts[0] ?? '');
            if ($sku && strtolower($sku) !== 'sku') {
                $skus[] = $sku;
            }
        }
        return array_values(array_unique(array_filter($skus)));
    }
}
