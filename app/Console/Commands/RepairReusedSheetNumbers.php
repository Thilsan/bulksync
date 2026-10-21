<?php

namespace App\Console\Commands;

use App\Models\ProductRequest;
use App\Services\ProductRequestSheetSyncService;
use App\Services\ProductRequestWorkflow;
use App\Services\SkuMappingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Puts back requests the sheet sync handed to another product.
 *
 * A row inserted on the master tab shifted every Request No below it, and the
 * sync — matching on the number alone — renamed each request after its
 * neighbour and poured the neighbour's SKUs in: BMW Fragrances became "SIMONE
 * PERELE" with 1,218 SKUs, still filed under Beauty, and its brand managers were
 * mailed about all of it.
 *
 * What it was comes from the audit trail: the first rename to an unrelated brand.
 * Which SKUs are its own comes from the category tab, the one place that says
 * which SKU belongs to which brand. The log cannot answer that — for a while the
 * sync added a neighbour's SKUs without renaming, so the damage often predates
 * the rename. The work people did on the request — its stage, its people, its
 * history — stays.
 */
class RepairReusedSheetNumbers extends Command
{
    protected $signature = 'product-requests:repair-reused-numbers
                            {--commit : Actually repair, instead of only reporting what would change}';

    protected $description = 'Restore requests the sheet sync renamed and filled from a different row';

    public function handle(SkuMappingService $mapping, ProductRequestWorkflow $workflow, ProductRequestSheetSyncService $sheet): int
    {
        $commit = (bool) $this->option('commit');

        if (!$commit) {
            $this->warn('Dry run — nothing will change. Pass --commit to repair.');
        }

        $backup     = [];
        $repaired   = 0;
        $removed    = 0;
        $unverified = [];
        $review     = [];

        $requests = ProductRequest::whereNotNull('sheet_request_no')
            ->with(['store', 'activities' => fn ($q) => $q->reorder()->orderBy('id')])
            ->orderBy('id')
            ->get();

        foreach ($requests as $request) {
            $plan = $this->plan($request);

            if (!$plan) {
                if ($request->activities->contains('action', 'skus_added')) {
                    $review[] = $request;
                }

                continue;
            }

            // Its own SKUs are the ones the tab lists under its own brand. A brand
            // the tab does not know cannot be checked, so nothing is taken away.
            $onTab = $sheet->tabSkusByBrand((string) $request->category)[ProductRequestSheetSyncService::brandKey($plan['brand'])] ?? null;
            $all   = $request->skus()->pluck('sku', 'id');
            $stray = $onTab
                ? $all->reject(fn ($sku) => in_array(strtoupper(trim($sku)), $onTab, true))->keys()
                : collect();

            // The tab knows the brand but none of these SKUs: the evidence
            // contradicts itself, and emptying the request is not a repair.
            $emptied = $onTab && $all->isNotEmpty() && $stray->count() === $all->count();

            if (!$onTab || $emptied) {
                $unverified[] = $request;
                $stray        = collect();
            }

            // How many it was created with — where "keep" agrees, the repair is right.
            $created = preg_match('/^(\d+) SKUs imported/', (string) $request->activities->firstWhere('action', 'created')?->remarks, $m)
                ? (int) $m[1]
                : null;

            $this->line(sprintf(
                '%-15s %-22s %-20s "%s" → "%s"   %s%s%s',
                $request->reference,
                $request->store?->name ?? '—',
                $request->category ?? '—',
                $request->brand,
                $plan['brand'],
                match (true) {
                    $emptied => "SKUs unchecked ({$all->count()}) — none are under that brand on the tab",
                    !$onTab  => "SKUs unchecked ({$all->count()})",
                    default  => 'keep ' . ($all->count() - $stray->count()) . " of {$all->count()} SKUs",
                },
                $created !== null ? "   (created with {$created})" : '',
                $plan['launch'] ? "   launch → {$plan['launch']}" : '',
            ));

            $repaired++;
            $removed += $stray->count();

            if (!$commit) {
                continue;
            }

            DB::transaction(function () use ($request, $plan, $mapping, $workflow, $stray, $onTab, &$backup) {
                $backup[$request->reference] = $request->skus()->whereIn('id', $stray)->get()->toArray();
                $request->skus()->whereIn('id', $stray)->delete();

                $request->update(array_filter([
                    'brand'              => $plan['brand'],
                    'online_launch_date' => $plan['launch'],
                ]) + [
                    // What the sync guard compares the sheet against from now on.
                    'sheet_snapshot' => [
                        'brand'              => $plan['brand'],
                        'priority'           => $request->priority,
                        'online_launch_date' => $plan['launch'] ?? $request->online_launch_date?->toDateString(),
                    ],
                ]);

                $mapping->rollUp($request->refresh());

                $workflow->log(
                    request:     $request,
                    action:      'sheet_repaired',
                    description: "Restored to {$plan['brand']} — the sheet had given its Request No to another product",
                    remarks:     $stray->isNotEmpty()
                        ? "{$stray->count()} SKU(s) not listed under {$plan['brand']} on the tab removed"
                        : "The tab could not confirm which SKUs are {$plan['brand']}'s, so they were left for a person to check",
                );
            });
        }

        $this->newLine();
        $this->info(($commit ? 'Repaired' : 'Would repair') . " {$repaired} request(s), removing {$removed} SKU(s).");

        // Deleting eighteen thousand rows on the strength of a spreadsheet is
        // not something to be unable to take back. One file per run, kept small.
        if ($commit && $backup) {
            $path = 'repair-reused-numbers-' . now()->format('Ymd-His') . '.json';
            Storage::disk('local')->put($path, json_encode($backup));
            $this->info('Every removed SKU row is saved in ' . Storage::disk('local')->path($path) . ' — delete it once you are happy.');
        }

        if ($unverified) {
            $this->newLine();
            $this->warn(count($unverified) . ' had their brand restored but their SKUs left as they are — the tab could not confirm which are theirs:');

            foreach ($unverified as $request) {
                $this->line("  {$request->reference}  {$request->category}");
            }
        }

        if ($review) {
            $this->newLine();
            $this->warn(count($review) . ' more had SKUs added later without a change of brand — usually a genuine top-up. Left alone; worth a glance:');

            foreach ($review as $request) {
                $this->line("  {$request->reference}  {$request->brand} / {$request->category}  " . $request->skus()->count() . ' SKUs');
            }
        }

        return self::SUCCESS;
    }

    /**
     * What this request was before it was taken over, or null when it never was.
     *
     * @return array{brand: string, launch: ?string}|null
     */
    private function plan(ProductRequest $request): ?array
    {
        $activities = $request->activities->values();

        foreach ($activities as $i => $activity) {
            if ($activity->action !== 'sheet_updated'
                || !preg_match('/brand: "(.*?)" → "(.*?)"/u', (string) $activity->remarks, $brand)
                || $this->sameBrand($brand[1], $brand[2])) {
                continue;
            }

            // Already put back since — a second run must not do it again.
            if ($activities->slice($i + 1)->contains('action', 'sheet_repaired')) {
                return null;
            }

            preg_match('/online_launch_date: "(.*?)" →/u', (string) $activity->remarks, $launch);

            return [
                'brand'  => $brand[1],
                'launch' => ($launch[1] ?? '') !== '' ? $launch[1] : null,
            ];
        }

        return null;
    }

    /** "Armani" and "ARMANI BEAUTY" are one brand corrected; "Azzaro" and "RYA COLLECTION" are not. */
    private function sameBrand(string $a, string $b): bool
    {
        $a = ProductRequestSheetSyncService::brandKey($a);
        $b = ProductRequestSheetSyncService::brandKey($b);

        return $a === $b || ($a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a)));
    }
}
