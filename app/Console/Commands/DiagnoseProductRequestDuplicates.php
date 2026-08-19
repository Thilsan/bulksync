<?php

namespace App\Console\Commands;

use App\Models\ProductRequest;
use App\Models\ProductRequestSheetSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only. Finds requests that look like the same piece of work and shows what
 * the sheet ledger says about each one, which is what separates the three ways a
 * pair can appear:
 *
 *   - different website tokens  → working as intended, one request per website
 *   - different Request No      → two rows on the sheet, or the sheet's numbering
 *                                 shifted between runs and the second run saw
 *                                 every row as new
 *   - no ledger row at all      → not created by the sync, or the ledger was lost
 */
class DiagnoseProductRequestDuplicates extends Command
{
    protected $signature = 'product-requests:diagnose-duplicates {--limit=20 : How many duplicate groups to show}';

    protected $description = 'Report product requests that duplicate each other, and why';

    public function handle(): int
    {
        $groups = ProductRequest::query()
            ->select('store_id', 'brand', 'category', DB::raw('COUNT(*) as copies'))
            ->groupBy('store_id', 'brand', 'category')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('copies')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No duplicate brand/category/website groups found.');
            return self::SUCCESS;
        }

        $reasons = [];

        foreach ($groups as $group) {
            $requests = ProductRequest::where('store_id', $group->store_id)
                ->where('brand', $group->brand)
                ->where('category', $group->category)
                ->with('store')
                ->orderBy('id')
                ->get();

            $ledger = ProductRequestSheetSync::whereIn('product_request_id', $requests->pluck('id'))
                ->get()
                ->keyBy('product_request_id');

            $this->newLine();
            $this->line("<comment>{$group->brand} / {$group->category}</comment> on "
                . ($requests->first()->store?->name ?? 'no website') . " — {$group->copies} copies");

            $rows = $requests->map(function ($request) use ($ledger) {
                $row = $ledger->get($request->id);

                return [
                    $request->reference,
                    $request->created_at->format('d M H:i'),
                    $request->total_skus,
                    $row ? $row->request_no : '—',
                    $row ? $row->website_token : '—',
                    ProductRequest::STATUS_LABELS[$request->status] ?? $request->status,
                ];
            })->all();

            $this->table(['Reference', 'Created', 'SKUs', 'Sheet No', 'Token', 'Status'], $rows);

            $reasons[$this->reasonFor($requests, $ledger)][] = $group->brand;
        }

        $this->newLine();
        $this->line('<comment>Why they are duplicated</comment>');

        foreach ($reasons as $reason => $brands) {
            $this->line(' • ' . count($brands) . " group(s): {$reason}");
        }

        return self::SUCCESS;
    }

    private function reasonFor($requests, $ledger): string
    {
        $rows = $requests->map(fn ($r) => $ledger->get($r->id))->filter();

        if ($rows->count() < $requests->count()) {
            return 'at least one copy has no sheet ledger row — not created by the sync, or the ledger was cleared';
        }

        if ($rows->pluck('website_token')->unique()->count() > 1) {
            return 'different website tokens — one request per website, working as intended';
        }

        if ($rows->pluck('request_no')->unique()->count() > 1) {
            return 'same website, different Request No — either two rows on the sheet or the sheet renumbered between runs';
        }

        return 'same Request No and token on both — the ledger should have prevented this';
    }
}
