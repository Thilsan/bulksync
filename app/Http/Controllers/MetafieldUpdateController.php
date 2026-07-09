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
        $content = file_get_contents($path);
        if ($content === false) return [];

        $content = $this->normalizeUtf8($content);

        // fgetcsv() can only split on \n and doesn't handle old-Mac-style \r-only
        // line endings (PHP 8 removed the auto_detect_line_endings ini setting
        // that used to compensate for this) — normalize every style to \n first
        // so parsing is correct no matter which "Save As" CSV variant was used.
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines   = array_filter(explode("\n", $content), fn ($line) => trim($line) !== '');

        $rows    = [];
        $headers = null;

        foreach ($lines as $line) {
            $fields = str_getcsv($line);

            if (!$headers) {
                $headers = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-'], '_', $h))), $fields);
                continue;
            }

            $fields = array_slice(array_pad($fields, count($headers), ''), 0, count($headers));
            $row    = array_combine($headers, $fields);

            $sku = $row['variant_sku'] ?? $row['sku'] ?? '';
            if (!trim($sku)) continue;

            $rows[] = [
                'sku'      => trim($sku),
                'material' => trim($row['material'] ?? ''),
                'features' => trim($row['features'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Excel commonly saves CSVs in Windows-1252, not UTF-8 — special characters
     * like ° become invalid UTF-8 bytes that crash json_encode() downstream
     * (both when building the Shopify request and in Laravel's JSON responses).
     * Force-correct to valid UTF-8 here, at the point bytes enter the system.
     */
    private function normalizeUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        return $converted !== false ? $converted : @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
