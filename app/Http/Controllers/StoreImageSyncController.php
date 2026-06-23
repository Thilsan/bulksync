<?php

namespace App\Http\Controllers;

use App\Jobs\RunStoreImageSyncJob;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class StoreImageSyncController extends Controller
{
    public function index()
    {
        $stores = Store::where('user_id', auth()->id())->get();
        return view('store-image-sync.index', compact('stores'));
    }

    public function start(Request $request)
    {
        $request->validate([
            'from_store' => 'required|integer',
            'to_store'   => 'required|integer|different:from_store',
            'skus'       => 'nullable|string',
            'csv_file'   => 'nullable|file|mimes:csv,txt|max:20480',
        ]);

        // Ensure both stores belong to the authenticated user
        $fromStore = Store::where('id', $request->from_store)
            ->where('user_id', auth()->id())->firstOrFail();
        $toStore   = Store::where('id', $request->to_store)
            ->where('user_id', auth()->id())->firstOrFail();

        $skus = $this->parseSkus($request);

        if (empty($skus)) {
            return back()->withErrors(['skus' => 'Please enter at least one SKU or upload a CSV.']);
        }

        $token = Str::random(40);

        Cache::put("store_sync_{$token}", [
            'status'     => 'pending',
            'total'      => count($skus),
            'processed'  => 0,
            'success'    => 0,
            'failed'     => 0,
            'from_store' => $fromStore->name,
            'to_store'   => $toStore->name,
        ], now()->addHours(2));

        RunStoreImageSyncJob::dispatch($token, $fromStore->id, $toStore->id, $skus)
            ->onQueue('bulkupload');

        return redirect()->route('store-image-sync.show', $token);
    }

    public function show(string $token)
    {
        $progress = Cache::get("store_sync_{$token}");
        abort_if(!$progress, 404);
        return view('store-image-sync.show', compact('token', 'progress'));
    }

    public function status(string $token)
    {
        $progress = Cache::get("store_sync_{$token}");
        abort_if(!$progress, 404);
        return response()->json($progress);
    }

    public function download(string $token)
    {
        $filePath = storage_path("app/store-sync/{$token}.csv");
        abort_unless(file_exists($filePath), 404, 'Result file not found.');

        return response()->download($filePath, "store-image-sync-result.csv", [
            'Content-Type' => 'text/csv',
        ]);
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
