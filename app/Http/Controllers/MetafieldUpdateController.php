<?php

namespace App\Http\Controllers;

use App\Jobs\MetafieldUpdateJob;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MetafieldUpdateController extends Controller
{
    public function index()
    {
        return view('metafield-update.index');
    }

    public function upload(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $store = Store::getActive();
        if (!$store) {
            return back()->with('warning', 'No active store selected.');
        }

        $rows = $this->parseCsv($request->file('csv_file')->getRealPath());

        if (empty($rows)) {
            return back()->with('warning', 'No valid rows found in CSV.');
        }

        $cacheKey = 'metafield_update_' . Str::uuid();

        Cache::put($cacheKey, [
            'status'    => 'pending',
            'total'     => count($rows),
            'processed' => 0,
            'results'   => [],
        ], 3600);

        MetafieldUpdateJob::dispatch($cacheKey, $store->id, $rows)->onQueue('bulkupload');

        return redirect()->route('metafield-update.status', ['key' => $cacheKey]);
    }

    public function status(Request $request)
    {
        $key  = $request->query('key');
        $data = Cache::get($key);

        if (!$data) {
            return redirect()->route('metafield-update.index')
                ->with('warning', 'Session expired or not found.');
        }

        return view('metafield-update.status', compact('data', 'key'));
    }

    public function poll(Request $request)
    {
        $key  = $request->query('key');
        $data = Cache::get($key);

        if (!$data) {
            return response()->json(['status' => 'expired']);
        }

        return response()->json($data);
    }

    private function parseCsv(string $path): array
    {
        $rows   = [];
        $handle = fopen($path, 'r');
        if (!$handle) return [];

        $headers = null;

        while (($line = fgetcsv($handle)) !== false) {
            if (!$headers) {
                // Normalize headers
                $headers = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-'], '_', $h))), $line);
                continue;
            }

            $row = array_combine($headers, array_pad($line, count($headers), ''));

            $sku = $row['variant_sku'] ?? $row['sku'] ?? '';
            if (!trim($sku)) continue;

            $rows[] = [
                'sku'      => trim($sku),
                'material' => trim($row['material'] ?? ''),
                'features' => trim($row['features'] ?? ''),
            ];
        }

        fclose($handle);
        return $rows;
    }
}
