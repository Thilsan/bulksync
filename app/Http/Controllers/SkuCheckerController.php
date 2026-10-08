<?php

namespace App\Http\Controllers;

use App\Jobs\BuildVariantBreakdownCsvJob;
use App\Jobs\RunCsvCompareJob;
use App\Jobs\RunSkuCheckJob;
use App\Models\SkuCheckItem;
use App\Models\SkuCheckSession;
use App\Models\Store;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use App\Support\Queues;

class SkuCheckerController extends Controller
{
    /**
     * The check form, with the work already done beside it.
     *
     * Somebody arriving here has usually run this before and wants to know how
     * the last run went as much as they want to start the next one — so the
     * recent runs and the running totals sit on the same screen rather than
     * behind a History link.
     */
    public function index()
    {
        $mine = SkuCheckSession::where('user_id', auth()->id());

        $totals = (clone $mine)->where('status', 'completed')
            ->selectRaw('COUNT(*) AS runs')
            ->selectRaw('COALESCE(SUM(total_skus), 0) AS skus')
            ->selectRaw('COALESCE(SUM(available_count), 0) AS mapped')
            ->selectRaw('COALESCE(SUM(not_available_count), 0) AS missing')
            ->first();

        $recent = (clone $mine)->with('store')->latest()->take(6)->get();

        return view('sku-checker.index', compact('totals', 'recent'));
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

            'variant_export' => [
                'status'   => $skuCheckSession->variant_export_status,
                'total'    => $skuCheckSession->variant_export_total,
                'scanned'  => $skuCheckSession->variant_export_scanned,
                'failed'   => $skuCheckSession->variant_export_failed,
                'progress' => $skuCheckSession->variantExportProgressPercent(),
                'error'    => $skuCheckSession->variant_export_error,
            ],
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

        RunSkuCheckJob::dispatch($session->id)->onQueue(Queues::SKU_CHECK);

        return redirect()->route('sku-checker.show', $session);
    }

    /**
     * One page of the result file, for the on-page table.
     *
     * The results live only as a CSV — a run of ten thousand SKUs writes no DB
     * rows — so paging reads the file and keeps just the slice asked for.
     */
    public function results(SkuCheckSession $skuCheckSession, Request $request)
    {
        abort_if($skuCheckSession->user_id !== auth()->id(), 403);

        $filePath = storage_path("app/sku-checks/{$skuCheckSession->id}.csv");

        if (!file_exists($filePath)) {
            return response()->json(['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 0]);
        }

        $filter  = $request->get('filter', 'all');
        $search  = mb_strtolower(trim((string) $request->get('q', '')));
        $page    = max(1, (int) $request->get('page', 1));
        $perPage = 50;

        $from = ($page - 1) * $perPage;
        $to   = $from + $perPage;

        $rows    = [];
        $matched = 0;

        $handle = fopen($filePath, 'r');
        fgetcsv($handle); // header

        while (($row = fgetcsv($handle)) !== false) {
            $status = strtolower($row[1] ?? '');

            if ($filter === 'available' && $status !== 'available') {
                continue;
            }
            if ($filter === 'not_available' && $status !== 'not available') {
                continue;
            }
            if ($search !== '' && !str_contains(mb_strtolower($row[0] ?? ''), $search)) {
                continue;
            }

            if ($matched >= $from && $matched < $to) {
                $rows[] = [
                    'sku'           => $row[0] ?? '',
                    'status'        => $row[1] ?? '',
                    'product_id'    => $row[2] ?? '',
                    'product_title' => $row[3] ?? '',
                    'published'     => ($row[4] ?? '') === 'TRUE',
                ];
            }

            $matched++;
        }

        fclose($handle);

        return response()->json([
            'rows'  => $rows,
            'total' => $matched,
            'page'  => $page,
            'pages' => (int) ceil($matched / $perPage),
        ]);
    }

    /**
     * The colour/size breakdown behind one SKU, read live from Shopify.
     *
     * Asked for a row at a time rather than collected during the run: the check
     * itself answers "is this SKU there", and only a handful of rows are ever
     * opened, so paying one call per row on demand is far cheaper than paying
     * one for every SKU in the list.
     */
    public function variants(SkuCheckSession $skuCheckSession, Request $request)
    {
        abort_if($skuCheckSession->user_id !== auth()->id(), 403);

        $sku = trim((string) $request->get('sku', ''));

        if ($sku === '') {
            return response()->json(['error' => 'No SKU given.'], 422);
        }

        $store = $skuCheckSession->store_id
            ? Store::find($skuCheckSession->store_id)
            : Store::getActive($skuCheckSession->user_id);

        $shopify = app(ShopifyService::class, ['store' => $store]);

        try {
            $breakdown = $shopify->getSkuVariantBreakdown($sku, true);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        if ($breakdown === null) {
            return response()->json(['error' => "No variant in Shopify carries the SKU {$sku}."], 404);
        }

        return response()->json($breakdown);
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

    /**
     * Start the colour/size CSV for a finished check.
     *
     * Opt-in, because it costs one Shopify lookup per mapped SKU where the
     * check itself costs one per fifty. Re-running is allowed — a store changes,
     * and the last file is the one worth keeping — but not while one is already
     * going, which would have two jobs writing the same file.
     */
    public function buildVariantExport(SkuCheckSession $skuCheckSession)
    {
        abort_if($skuCheckSession->user_id !== auth()->id(), 403);

        if ($skuCheckSession->status !== 'completed') {
            return response()->json(['error' => 'The check has not finished yet.'], 422);
        }

        if ($skuCheckSession->variant_export_status === 'running') {
            return response()->json(['error' => 'A variant export is already running.'], 409);
        }

        $skuCheckSession->update([
            'variant_export_status'  => 'pending',
            'variant_export_scanned' => 0,
            'variant_export_failed'  => 0,
            'variant_export_error'   => null,
        ]);

        BuildVariantBreakdownCsvJob::dispatch($skuCheckSession->id)->onQueue(Queues::SKU_CHECK);

        return response()->json(['status' => 'pending']);
    }

    public function downloadVariantExport(SkuCheckSession $skuCheckSession)
    {
        abort_if($skuCheckSession->user_id !== auth()->id(), 403);

        $filePath = storage_path("app/sku-checks/{$skuCheckSession->id}-variants.csv");
        abort_unless(file_exists($filePath), 404, 'Variant export not found.');

        return response()->download(
            $filePath,
            "sku-check-{$skuCheckSession->id}-variants.csv",
            ['Content-Type' => 'text/csv']
        );
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

        RunCsvCompareJob::dispatch($session->id)->onQueue(Queues::SKU_CHECK);

        return redirect()->route('sku-checker.show', $session);
    }

    public function destroy(SkuCheckSession $skuCheckSession)
    {
        abort_if($skuCheckSession->user_id !== auth()->id(), 403);
        // Both files, or the export outlives the session it describes and the
        // directory only ever grows.
        foreach ([".csv", "-variants.csv"] as $suffix) {
            $filePath = storage_path("app/sku-checks/{$skuCheckSession->id}{$suffix}");
            if (file_exists($filePath)) {
                unlink($filePath);
            }
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
