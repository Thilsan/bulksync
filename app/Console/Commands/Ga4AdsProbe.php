<?php

namespace App\Console\Commands;

use App\Services\Ga4Client;
use App\Services\GoogleAdsViaGa4Service;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Check, against a live property, that the Google Ads tab asks GA4 questions
 * it can answer.
 *
 * The advertiser* cost metrics only join to the few dimensions Google Ads
 * reports by, and one incompatible field fails the whole batch it is sent in,
 * so a report that looks right on paper can take every card down. This runs
 * GA4's own checkCompatibility over exactly the requests the tab sends, then
 * the same requests with the visitor-sessions country filter added, and then
 * the real reports, printing what came back.
 *
 * Read-only, and costs nothing but GA4 quota.
 */
class Ga4AdsProbe extends Command
{
    protected $signature = 'ga4:ads-probe
        {property : GA4 property id, e.g. 307311411}
        {--from= : Start date, Y-m-d (default: first of this month)}
        {--to= : End date, Y-m-d (default: today)}';

    protected $description = 'Check which Google Ads metrics a GA4 property can report, and show what it returns';

    public function handle(Ga4Client $client, GoogleAdsViaGa4Service $ads): int
    {
        if (! $client->configured()) {
            $this->error('No readable GA4 key at ' . config('services.ga4.credentials'));

            return self::FAILURE;
        }

        $property = (string) $this->argument('property');
        $from     = Carbon::parse($this->option('from') ?: Carbon::today()->startOfMonth()->toDateString())->startOfDay();
        $to       = Carbon::parse($this->option('to') ?: Carbon::today()->toDateString())->startOfDay();
        $requests = $ads->requests($from, $to);

        $this->info("Property {$property}, {$from->toDateString()} to {$to->toDateString()}");

        // ── 1. Compatibility of what the tab actually sends ─────────────────
        $this->newLine();
        $this->line('<comment>1. checkCompatibility, as the tab sends them</comment>');
        foreach ($requests as $name => $request) {
            $this->compatibility($client, $property, $name, $request);
        }

        // ── 2. The same, with the sessions tab's country filter ─────────────
        $this->newLine();
        $this->line('<comment>2. checkCompatibility, with a country exclusion filter added</comment>');
        $countryFilter = ['notExpression' => ['filter' => [
            'fieldName'    => 'country',
            'inListFilter' => ['values' => ['Singapore']],
        ]]];
        foreach (['current', 'campaigns'] as $name) {
            $withCountry = $requests[$name];
            $withCountry['dimensionFilter'] = ['andGroup' => ['expressions' => [
                $requests[$name]['dimensionFilter'],
                $countryFilter,
            ]]];
            $this->compatibility($client, $property, "{$name}+country", $withCountry);
        }

        // ── 3. The real reports ─────────────────────────────────────────────
        $this->newLine();
        $this->line('<comment>3. batchRunReports, as the tab sends them</comment>');
        try {
            $reports = $client->batchReport($property, array_values($requests));
            foreach (array_keys($requests) as $i => $name) {
                $this->report($name, $reports[$i] ?? []);
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }

        // ── 4. The same totals with the country filter, actually run ────────
        $this->newLine();
        $this->line('<comment>4. totals with the country filter, actually run</comment>');
        $current = $requests['current'];
        $current['dimensionFilter'] = ['andGroup' => ['expressions' => [$current['dimensionFilter'], $countryFilter]]];
        try {
            $this->report('current+country', $client->batchReport($property, [$current])[0] ?? []);
        } catch (\Throwable $e) {
            $this->error('  ' . $e->getMessage());
        }

        // ── 5. What non-Ads sessions are labelled, so the exclusion is right ─
        $this->newLine();
        $this->line('<comment>5. sessionGoogleAdsCampaignName with no filter (top 8 by sessions)</comment>');
        try {
            $this->report('labels', $client->batchReport($property, [[
                'dateRanges' => $requests['current']['dateRanges'],
                'dimensions' => [['name' => 'sessionGoogleAdsCampaignName']],
                'metrics'    => [['name' => 'sessions'], ['name' => 'advertiserAdCost'], ['name' => 'totalRevenue'], ['name' => 'purchaseRevenue']],
                'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit'      => 8,
            ]])[0] ?? []);
        } catch (\Throwable $e) {
            $this->error('  ' . $e->getMessage());
        }

        return self::SUCCESS;
    }

    private function compatibility(Ga4Client $client, string $property, string $name, array $request): void
    {
        try {
            $answer = $client->checkCompatibility($property, $request);
        } catch (\Throwable $e) {
            $this->error("  {$name}: " . $e->getMessage());

            return;
        }

        $bad = [];
        foreach ($answer['dimensionCompatibilities'] ?? [] as $d) {
            if (($d['compatibility'] ?? '') !== 'COMPATIBLE') {
                $bad[] = ($d['dimensionMetadata']['apiName'] ?? '?') . '=' . ($d['compatibility'] ?? '?');
            }
        }
        foreach ($answer['metricCompatibilities'] ?? [] as $m) {
            if (($m['compatibility'] ?? '') !== 'COMPATIBLE') {
                $bad[] = ($m['metricMetadata']['apiName'] ?? '?') . '=' . ($m['compatibility'] ?? '?');
            }
        }

        $this->line($bad ? "  {$name}: <error>" . implode(', ', $bad) . '</error>' : "  {$name}: all compatible");
    }

    private function report(string $name, array $report): void
    {
        $metrics = array_map(fn ($h) => $h['name'], $report['metricHeaders'] ?? []);
        $rows    = $report['rows'] ?? [];

        $this->line("  {$name}: " . \count($rows) . ' row(s)' . (isset($report['rowCount']) ? " of {$report['rowCount']}" : ''));

        foreach (\array_slice($rows, 0, 8) as $row) {
            $dims = array_map(fn ($d) => $d['value'] ?? '', $row['dimensionValues'] ?? []);
            $vals = [];
            foreach ($row['metricValues'] ?? [] as $i => $v) {
                $vals[] = ($metrics[$i] ?? $i) . '=' . ($v['value'] ?? '');
            }
            $this->line('    ' . ($dims ? '[' . implode(' | ', $dims) . '] ' : '') . implode('  ', $vals));
        }
    }
}
