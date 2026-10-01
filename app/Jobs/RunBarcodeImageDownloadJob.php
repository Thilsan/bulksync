<?php

namespace App\Jobs;

use App\Models\BarcodeImageItem;
use App\Models\BarcodeImageSession;
use App\Services\ProductImageScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Works down a list of barcodes, one at a time, filling a folder per barcode.
 *
 * A run is given several websites rather than one, because nobody knows which
 * catalogue carries a given article — and because a site can refuse this
 * server outright, which turned a forty-one barcode list into forty-one empty
 * rows on Albertoshop. Each barcode walks the list in order and stops at the
 * first site that hands over pictures, so one blocked or thin catalogue no
 * longer decides the whole run.
 *
 * Deliberately serial: this is somebody else's public website being read, and
 * a run of two thousand barcodes fanned out across workers is indistinguishable
 * from an attack. One barcode at a time with a short pause is slower and stays
 * welcome. The pause is kept per site, so moving between sites spreads the
 * load rather than hurrying any one of them.
 *
 * A barcode that cannot be found, or whose images will not download, is
 * recorded as such and the run carries on. Only something that breaks the run
 * itself — every site refusing, the disk filling — fails the session.
 */
class RunBarcodeImageDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10800;
    public int $tries   = 1;

    /** The least this waits between requests to one site, where it asks for nothing more. */
    private const PAUSE_MICROSECONDS = 300_000;

    public function __construct(public readonly int $sessionId) {}

    public function handle(ProductImageScraper $scraper): void
    {
        $session = BarcodeImageSession::findOrFail($this->sessionId);

        $barcodes = array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[\r\n,;\t]+/', (string) $session->raw_barcodes) ?: [])
        )));

        $sites = array_values(array_filter(array_map(
            fn (string $site) => $scraper->normaliseSite($site),
            $session->sites(),
        )));

        if ($sites === [] || $barcodes === []) {
            $session->update([
                'status'        => 'failed',
                'error_message' => $sites === []
                    ? 'That website address could not be read.'
                    : 'No barcodes to look up.',
                'raw_barcodes'  => null,
            ]);

            return;
        }

        // Asked once per site, before a thousand barcodes are looked up one at
        // a time: a site that cannot be read will report every one of them as
        // missing, and "your list is wrong" is the wrong thing to tell somebody
        // whose list is fine. A site that fails here is dropped from the run
        // rather than stopping it — that is the whole point of a list.
        $usable  = [];
        $refused = [];

        foreach ($sites as $site) {
            // Which of the two it is matters to the person reading the screen:
            // a blocked site is somebody else's rule and is fixed by asking
            // them, while an unreadable one will never work however often it
            // is tried.
            if ($why = $scraper->refusedBy($site)) {
                $refused[$site] = ['label' => 'Blocked', 'kind' => 'blocked', 'why' => $why];

                Log::info("RunBarcodeImageDownloadJob: skipping {$site} — blocked");

                continue;
            }

            if ($why = $scraper->searchability($site, $barcodes[0])) {
                $refused[$site] = ['label' => 'Cannot be read', 'kind' => 'unreadable', 'why' => $why];

                Log::info("RunBarcodeImageDownloadJob: skipping {$site} — unreadable");

                continue;
            }

            // What the site asks for in robots.txt, where that is more than the
            // courtesy pause. It is the site's call how fast it is read.
            $usable[$site] = max(
                self::PAUSE_MICROSECONDS,
                (int) round($scraper->crawlDelaySeconds($site) * 1_000_000),
            );
        }

        if ($usable === []) {
            $session->update([
                'status'        => 'failed',
                'site_url'      => $sites[0],
                'site_urls'     => $sites,
                'site_issues'   => $refused,
                'raw_barcodes'  => null,
                'error_message' => $this->whyNoneCanBeRead($refused),
            ]);

            return;
        }

        $session->update([
            'status'         => 'running',
            'site_url'       => array_key_first($usable),
            'site_urls'      => $sites,
            'site_issues'    => $refused ?: null,
            'total_barcodes' => count($barcodes),
            'error_message'  => $refused === [] ? null : $this->whySomeWereSkipped($refused),
        ]);

        Log::info("RunBarcodeImageDownloadJob: {$session->total_barcodes} barcodes against "
            . implode(', ', array_keys($usable)) . ' for session ' . $this->sessionId);

        $processed  = 0;
        $found      = 0;
        $missing    = 0;
        $downloaded = 0;

        // When each site may next be asked, so the wait one site requests does
        // not hold up the others.
        $nextTurn = array_fill_keys(array_keys($usable), 0.0);

        try {
            foreach ($barcodes as $barcode) {
                $item = BarcodeImageItem::create([
                    'barcode_image_session_id' => $session->id,
                    'barcode'                  => $barcode,
                    'status'                   => 'pending',
                ]);

                $attempt = $this->firstSiteWithPictures(
                    $scraper, $session, $barcode, $usable, $nextTurn
                );

                $processed++;

                if ($attempt['saved'] > 0) {
                    $found++;
                    $downloaded += $attempt['saved'];
                } else {
                    $missing++;
                }

                $item->update($attempt['item']);

                $session->update([
                    'processed'         => $processed,
                    'found_count'       => $found,
                    'missing_count'     => $missing,
                    'images_downloaded' => $downloaded,
                ]);
            }

            $session->update([
                'status'       => 'completed',
                'raw_barcodes' => null,
            ]);

            Log::info("RunBarcodeImageDownloadJob: done — {$found} with images, {$missing} without, {$downloaded} files.");

        } catch (\Throwable $e) {
            Log::error('RunBarcodeImageDownloadJob failed: ' . $e->getMessage());

            $session->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'raw_barcodes'  => null,
            ]);
        }
    }

    /**
     * One barcode against the list, stopping at the first site that gives
     * pictures.
     *
     * A site that has the product but hands over nothing downloadable is not
     * the end of it — the next site is asked, because the point of a list is
     * that one catalogue's bad day is not the barcode's answer. What is kept
     * is the best attempt: pictures beat a product page, and a product page
     * beats nothing.
     *
     * @param  array<string,int>   $usable   site => microseconds it asks between requests
     * @param  array<string,float> $nextTurn site => when it may next be asked
     * @return array{saved: int, item: array<string,mixed>}
     */
    private function firstSiteWithPictures(
        ProductImageScraper $scraper,
        BarcodeImageSession $session,
        string $barcode,
        array $usable,
        array &$nextTurn,
    ): array {
        $tried = 0;
        $best  = null;

        foreach ($usable as $site => $pause) {
            $this->waitTurn($nextTurn, $site);

            $tried++;

            try {
                $result = $scraper->forBarcode($site, $barcode);
            } catch (\Throwable $e) {
                Log::warning("Barcode {$barcode} failed on {$site}: " . $e->getMessage());

                $best ??= ['saved' => 0, 'item' => ['status' => 'failed', 'message' => $e->getMessage()]];
                $nextTurn[$site] = microtime(true) + $pause / 1_000_000;

                continue;
            }

            $nextTurn[$site] = microtime(true) + $pause / 1_000_000;

            if (!$result['url']) continue;

            // The folder is named after the barcode, whether or not any image
            // lands in it — an empty folder is itself the answer to "did we get
            // pictures for this one".
            $folder = $session->folderFor($barcode);
            $stem   = BarcodeImageSession::safeFolder($barcode);
            $saved  = 0;

            foreach ($result['images'] as $index => $imageUrl) {
                try {
                    if ($scraper->download($imageUrl, $folder, $stem, $index + 1)) $saved++;
                } catch (\Throwable $e) {
                    Log::debug("Image {$imageUrl} for {$barcode} failed: " . $e->getMessage());
                }
            }

            if ($saved > 0) {
                return [
                    'saved' => $saved,
                    'item'  => [
                        'status'        => 'found',
                        'product_url'   => $result['url'],
                        'source_site'   => $site,
                        'product_title' => $result['title'],
                        'image_count'   => $saved,
                        'message'       => ($result['matches'] ?? 1) > 1
                            ? $result['matches'] . ' product pages matched this barcode — all of their images are in the folder.'
                            : null,
                    ],
                ];
            }

            $best ??= [
                'saved' => 0,
                'item'  => [
                    'status'        => 'not_found',
                    'product_url'   => $result['url'],
                    'source_site'   => $site,
                    'product_title' => $result['title'],
                    'image_count'   => 0,
                    'message'       => 'The product page was found, but none of its images could be downloaded.',
                ],
            ];
        }

        return $best ?? [
            'saved' => 0,
            'item'  => [
                'status'  => 'not_found',
                'message' => $tried > 1
                    ? "No product matched this barcode on any of the {$tried} websites."
                    : 'No product on that site matched this barcode.',
            ],
        ];
    }

    /** Holds off until the site in question has had the pause it asked for. */
    private function waitTurn(array &$nextTurn, string $site): void
    {
        $owed = ($nextTurn[$site] ?? 0.0) - microtime(true);

        if ($owed > 0) usleep((int) round($owed * 1_000_000));
    }

    /**
     * Why the run stopped: every site on the list, and what each said.
     *
     * @param array<string,array{label: string, kind: string, why: string}> $refused
     */
    private function whyNoneCanBeRead(array $refused): string
    {
        if (count($refused) === 1) return reset($refused)['why'];

        $lines = [];

        foreach ($refused as $site => $issue) {
            $lines[] = parse_url($site, PHP_URL_HOST) . ' — ' . $issue['why'];
        }

        return 'None of the websites on this run could be read.' . "\n\n" . implode("\n\n", $lines);
    }

    /**
     * A note on the run that carried on without one of its sites, so that
     * "half the barcodes are missing" has its explanation in the same place.
     *
     * @param array<string,array{label: string, kind: string, why: string}> $refused
     */
    private function whySomeWereSkipped(array $refused): string
    {
        $lines = [];

        foreach ($refused as $site => $issue) {
            $lines[] = parse_url($site, PHP_URL_HOST) . ' was left out of this run — ' . $issue['why'];
        }

        return implode("\n\n", $lines);
    }

    public function failed(\Throwable $e): void
    {
        BarcodeImageSession::where('id', $this->sessionId)->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
