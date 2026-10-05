<?php

namespace App\Console\Commands;

use App\Models\BarcodeImageSession;
use App\Services\ProductImageScraper;
use Illuminate\Console\Command;

/**
 * Asks the sites of past runs that found nothing whether they refuse this
 * server, and marks the runs blocked where they do.
 *
 * Runs made before blocks were noticed mid-run finished as "Completed" with
 * every barcode missing, and kept no record of why. This asks each site once,
 * from the server, for one of that run's barcodes — the same request the run
 * made — so the answer is the server's and not the office's.
 *
 * It is today's answer, not the one the run got: a site that has lifted its
 * block since is left alone, and that is the right result, because re-running
 * it would now work.
 */
class RecheckBarcodeImageBlocks extends Command
{
    protected $signature = 'barcode-images:recheck-blocks {--dry-run : Report what would be marked without changing anything}';

    protected $description = 'Mark past image grabs that found nothing as IP blocked where their site refuses this server';

    public function handle(ProductImageScraper $scraper): int
    {
        $sessions = BarcodeImageSession::query()
            ->whereIn('status', ['completed', 'failed'])
            ->where('found_count', 0)
            ->where('blocked_count', 0)
            ->where('total_barcodes', '>', 0)
            ->orderBy('id')
            ->get();

        if ($sessions->isEmpty()) {
            $this->info('No runs to check.');

            return self::SUCCESS;
        }

        // One question per site however many runs used it.
        $verdicts = [];
        $marked   = 0;

        foreach ($sessions as $session) {
            $sample = $session->items()->orderBy('id')->value('barcode');
            $issues = $session->site_issues ?? [];

            foreach ($session->sites() as $raw) {
                $site = $scraper->normaliseSite($raw);

                if (!$site || isset($issues[$site])) continue;

                $verdicts[$site] ??= $this->blockedWhy($scraper, $site, $sample);

                if ($verdicts[$site]) {
                    $issues[$site] = ['label' => 'Blocked', 'kind' => 'blocked', 'why' => $verdicts[$site]];
                }
            }

            $blockedSites = array_filter($issues, fn ($i) => is_array($i) && ($i['kind'] ?? null) === 'blocked');

            if ($blockedSites === []) {
                $this->line("#{$session->id} " . parse_url($session->site_url, PHP_URL_HOST) . ' — not blocked');

                continue;
            }

            $marked++;
            $this->warn("#{$session->id} " . implode(', ', array_map(
                fn ($s) => parse_url($s, PHP_URL_HOST), array_keys($blockedSites)
            )) . ' — IP blocked');

            if ($this->option('dry-run')) continue;

            $update = ['site_issues' => $issues];

            // Every site refused: none of the misses were real misses.
            if (count($blockedSites) === count($session->sites())) {
                $refused = $session->items()->where('status', 'not_found')->update([
                    'status'  => 'blocked',
                    'message' => 'The site refuses this server, so whether it stocks this barcode is unknown.',
                ]);

                $update['blocked_count'] = $refused;
            }

            $session->update($update);
        }

        $this->info(($this->option('dry-run') ? 'Would mark ' : 'Marked ') . "{$marked} of {$sessions->count()} runs as blocked.");

        return self::SUCCESS;
    }

    private function blockedWhy(ProductImageScraper $scraper, string $site, ?string $sample): ?string
    {
        if ($why = $scraper->refusedBy($site)) return $why;

        if (!$sample) return null;

        try {
            $status = $scraper->forBarcode($site, $sample)['blocked'];
        } catch (\Throwable) {
            return null;
        }

        return $status
            ? "This site refuses this server's searches: its security service answered with a block page (HTTP {$status}) "
                . 'rather than results, so the barcodes on this run were never really looked up and the list is not at '
                . 'fault. Ask them to allow this server, or run the grab from a network they already accept.'
            : null;
    }
}
