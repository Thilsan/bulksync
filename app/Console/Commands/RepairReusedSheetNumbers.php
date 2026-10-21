<?php

namespace App\Console\Commands;

use App\Models\ProductRequest;
use App\Services\ProductRequestWorkflow;
use App\Services\SkuMappingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts back requests the sheet sync handed to another product.
 *
 * The sheet gave one Request No to several rows, and the sync — matching on the
 * number alone — renamed the request to each in turn and poured their SKUs in:
 * BMW Fragrances became "SIMONE PERELE" with 1,218 SKUs, still filed under
 * Beauty, and its brand managers were mailed about all of it.
 *
 * The audit trail kept what it was. The first rename from one brand to an
 * unrelated one marks where it went wrong; the brand and launch date are put
 * back from that entry, and every SKU added from then on is removed. The work
 * people did on the request — its stage, its people, its history — stays.
 */
class RepairReusedSheetNumbers extends Command
{
    protected $signature = 'product-requests:repair-reused-numbers
                            {--commit : Actually repair, instead of only reporting what would change}';

    protected $description = 'Restore requests the sheet sync renamed and filled from a different row';

    public function handle(SkuMappingService $mapping, ProductRequestWorkflow $workflow): int
    {
        $commit = (bool) $this->option('commit');

        if (!$commit) {
            $this->warn('Dry run — nothing will change. Pass --commit to repair.');
        }

        $repaired = 0;
        $review   = [];

        $requests = ProductRequest::whereNotNull('sheet_request_no')
            ->with(['activities' => fn ($q) => $q->reorder()->orderBy('id')])
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

            $stray = $request->skus()->where('created_at', '>', $plan['since'])->count();

            $this->line(sprintf(
                '%-15s %-22s %-14s "%s" → "%s"   remove %d of %d SKUs%s',
                $request->reference,
                $request->store?->name ?? '—',
                $request->category ?? '—',
                $request->brand,
                $plan['brand'],
                $stray,
                $request->skus()->count(),
                $plan['launch'] ? "   launch → {$plan['launch']}" : '',
            ));

            $repaired++;

            if (!$commit) {
                continue;
            }

            DB::transaction(function () use ($request, $plan, $mapping, $workflow, $stray) {
                $request->skus()->where('created_at', '>', $plan['since'])->delete();

                $request->update(array_filter([
                    'brand'              => $plan['brand'],
                    'online_launch_date' => $plan['launch'],
                ]) + [
                    // What the guard compares the sheet against from now on.
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
                    remarks:     "{$stray} SKU(s) from other rows removed",
                );
            });
        }

        $this->newLine();
        $this->info(($commit ? 'Repaired' : 'Would repair') . " {$repaired} request(s).");

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
     * Where this request was taken over, or null when it never was.
     *
     * @return array{brand: string, launch: ?string, since: \Carbon\CarbonInterface}|null
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

            // The other row's SKUs are added just before the rename, in the same
            // pass. Everything after the entry before that is not this request's.
            $j = $i - 1;

            while ($j >= 0 && $activities[$j]->action === 'skus_added'
                && $activities[$j]->created_at->diffInMinutes($activity->created_at, true) <= 10) {
                $j--;
            }

            return [
                'brand'  => $brand[1],
                'launch' => ($launch[1] ?? '') !== '' ? $launch[1] : null,
                'since'  => $j >= 0 ? $activities[$j]->created_at : $request->created_at,
            ];
        }

        return null;
    }

    /** "Armani" and "ARMANI BEAUTY" are one brand corrected; "Azzaro" and "RYA COLLECTION" are not. */
    private function sameBrand(string $a, string $b): bool
    {
        $a = preg_replace('/[^A-Z0-9]/', '', strtoupper($a));
        $b = preg_replace('/[^A-Z0-9]/', '', strtoupper($b));

        return $a === $b || ($a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a)));
    }
}
