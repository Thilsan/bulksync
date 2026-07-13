<?php

namespace App\Http\Controllers;

use App\Jobs\RunStoreImageSyncJob;
use App\Models\Store;
use App\Models\StoreMigrationSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class StoreImageSyncController extends Controller
{
    public function index()
    {
        $user   = auth()->user();
        $stores = $user->is_super_admin
            ? Store::orderBy('name')->get()
            : $user->stores()->orderBy('name')->get();

        $sessionQuery = StoreMigrationSession::query();
        if (!$user->is_super_admin) {
            $sessionQuery->where('user_id', $user->id);
        }

        $stats = [
            'total_runs'     => (clone $sessionQuery)->count(),
            'full_product'   => (clone $sessionQuery)->where('migration_type', 'full_product')->count(),
            'images_only'    => (clone $sessionQuery)->where('migration_type', 'images_only')->count(),
            'total_migrated' => (clone $sessionQuery)->sum('success_count'),
            'total_failed'   => (clone $sessionQuery)->sum('failed_count'),
        ];

        $recentSessions = (clone $sessionQuery)->with(['fromStore', 'toStore'])->latest()->limit(10)->get();

        return view('store-image-sync.index', compact('stores', 'stats', 'recentSessions'));
    }

    public function start(Request $request)
    {
        $request->validate([
            'from_store'     => 'required|integer',
            'to_store'       => 'required|integer|different:from_store',
            'skus'           => 'nullable|string',
            'csv_file'       => 'nullable|file|mimes:csv,txt|max:20480',
            'migration_type' => 'required|in:images_only,full_product',
        ]);

        // Ensure both stores belong to the authenticated user
        $user      = auth()->user();
        $fromStore = $user->is_super_admin
            ? Store::findOrFail($request->from_store)
            : $user->stores()->findOrFail($request->from_store);
        $toStore   = $user->is_super_admin
            ? Store::findOrFail($request->to_store)
            : $user->stores()->findOrFail($request->to_store);

        $skus = $this->parseSkus($request);

        if (empty($skus)) {
            return back()->withErrors(['skus' => 'Please enter at least one SKU or upload a CSV.']);
        }

        $token = Str::random(40);

        Cache::put("store_sync_{$token}", [
            'status'         => 'pending',
            'total'          => count($skus),
            'processed'      => 0,
            'success'        => 0,
            'failed'         => 0,
            'from_store'     => $fromStore->name,
            'to_store'       => $toStore->name,
            'migration_type' => $request->migration_type,
        ], now()->addHours(2));

        StoreMigrationSession::create([
            'user_id'        => $user->id,
            'from_store_id'  => $fromStore->id,
            'to_store_id'    => $toStore->id,
            'migration_type' => $request->migration_type,
            'token'          => $token,
            'status'         => 'running',
            'total_skus'     => count($skus),
        ]);

        RunStoreImageSyncJob::dispatch($token, $fromStore->id, $toStore->id, $skus, $request->migration_type)
            ->onQueue('bulkupload');

        return redirect()->route('store-image-sync.show', $token);
    }

    public function show(string $token)
    {
        $progress = $this->resolveProgress($token);
        abort_if(!$progress, 404);
        return view('store-image-sync.show', compact('token', 'progress'));
    }

    public function status(string $token)
    {
        $progress = $this->resolveProgress($token);
        abort_if(!$progress, 404);
        return response()->json($progress);
    }

    /**
     * Live progress is cached for 2 hours only (fast, no DB writes per SKU).
     * Once that expires, fall back to the persisted session record so
     * "View" links from the history table below keep working indefinitely.
     */
    private function resolveProgress(string $token): ?array
    {
        $progress = Cache::get("store_sync_{$token}");
        if ($progress) return $progress;

        $session = StoreMigrationSession::where('token', $token)->with(['fromStore', 'toStore'])->first();
        if (!$session) return null;

        return [
            'status'         => $session->status,
            'total'          => $session->total_skus,
            'processed'      => $session->total_skus,
            'success'        => $session->success_count,
            'failed'         => $session->failed_count,
            'from_store'     => $session->fromStore?->name,
            'to_store'       => $session->toStore?->name,
            'migration_type' => $session->migration_type,
            'error'          => $session->error_message,
        ];
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
