<?php

namespace App\Services;

use App\Jobs\ValidateProductRequestSkusJob;
use App\Models\ProductRequest;
use App\Models\ProductRequestSku;
use App\Models\ProductRequestSheetSync;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Turns rows on the shared "PRODUCT LISTING REQUEST" tracking sheet into real
 * ProductRequest records.
 *
 * Only the master tab is ever scanned for *new* requests. The per-category
 * tabs (Perfumes & Cosmetics, Luggage, ...) are a running historical SKU
 * catalog spanning thousands of already-live products — they are read only
 * to pull the SKUs for a master row that has already been matched, never
 * treated as a source of new requests on their own. See
 * config/product_request_sync.php for the department/website mappings this
 * relies on.
 */
class ProductRequestSheetSyncService
{
    public function __construct(
        private OneDriveService $drive,
        private ProductRequestWorkflow $workflow,
        private SkuMappingService $mapping,
    ) {}

    /**
     * @return array{created: int, unmatched_store: int, unmatched_department: int, unmatched_skus: int, errors: int, skipped_existing: int, log: array<int, string>}
     */
    public function run(bool $commit = false): array
    {
        // Who owns the requests this creates when the sheet names nobody we know.
        $syncUser = User::where('email', config('product_request_sync.sync_user_email'))->firstOrFail();

        // The sheet is read through its own Azure app and its own sign-in, not
        // through anyone's account here — see OneDriveService::asServiceAccount().
        $this->drive->asServiceAccount();

        $item = $this->drive->resolveShareItem(config('product_request_sync.master_sheet_url'));

        $masterValues = $this->drive->worksheetValues($item['driveId'], $item['itemId'], config('product_request_sync.master_worksheet'));
        $header       = array_map('trim', $masterValues[0] ?? []);
        $rows         = array_slice($masterValues, 1);

        $result = [
            'created'               => 0,
            'backfilled'            => 0,
            'skus_added'            => 0,
            'count_mismatch'        => 0,
            'updated'               => 0,
            'conflicts'             => 0,
            'ignored'               => 0,
            'unmatched_store'       => 0,
            'unmatched_department'  => 0,
            'unmatched_skus'        => 0,
            'errors'                => 0,
            'skipped_existing'      => 0,
            'already_listed'        => 0,
            'swapped_dates'         => 0,
            'log'                   => [],
        ];

        $sheetCache = [];

        foreach ($rows as $row) {
            $data = array_combine($header, array_pad($row, count($header), null));

            $requestNo = (int) ($data['Request No'] ?? 0);
            if (!$requestNo) {
                continue;
            }

            // Listed By / Listed Date mean e-commerce already handled this row by
            // hand, before the automation existed — there is no work left in it.
            //
            // Unless the sheet calls it Completed. Then it is a product that went
            // live and has no record here at all, which is the one thing worth
            // importing: it comes in published rather than as work to do.
            if (filled($data['Listed By'] ?? null) || filled($data['Listed Date'] ?? null)) {
                if (!$this->sheetSaysPublished($data)) {
                    $result['already_listed']++;
                    continue;
                }
            }

            $department = trim((string) ($data['Department'] ?? ''));
            $deptConfig = $this->departmentConfigFor($department);

            $tokens = $this->splitWebsiteTokens((string) ($data['Website'] ?? ''));

            foreach ($tokens as $token) {
                $existing = ProductRequestSheetSync::where('request_no', $requestNo)
                    ->where('website_token', $token)
                    ->first();

                if ($existing && $existing->status === 'created') {
                    // Requests created before the sheet's Request No / Requested By
                    // were stored have those two columns empty — fill them in on the
                    // next run rather than making the team re-create the request.
                    $fixed = $this->backfillExisting($existing, $data, $commit);

                    // The category tabs are appended to over time: ten SKUs today,
                    // ten more against the same brand and date tomorrow. Without
                    // this the request keeps whatever it had when it was created
                    // and the later ones are never seen by anyone.
                    if ($deptConfig && $existing->productRequest) {
                        $sheetName = $deptConfig['sheet'];

                        if (!array_key_exists($sheetName, $sheetCache)) {
                            $sheetCache[$sheetName] = $this->drive->worksheetValues($item['driveId'], $item['itemId'], $sheetName);
                        }

                        $swapNote = null;

                        $added = $this->addNewSkus(
                            $existing->productRequest,
                            $this->matchSkus($sheetCache[$sheetName], $data['Request Date'] ?? null, (string) ($data['Brand'] ?? ''), $swapNote),
                            $commit,
                        );

                        if ($added > 0) {
                            $fixed[] = "{$added} new SKU(s)";
                            $result['skus_added'] += $added;
                        }

                        if ($swapNote && $added > 0) {
                            $result['swapped_dates']++;
                            $result['log'][] = "{$existing->productRequest->reference} — Request No {$requestNo} / {$token} "
                                . "({$data['Brand']}): {$swapNote}";
                        }

                        // Checked on every run, not only at import: a request that
                        // came in short stays short, and the first import is the
                        // one moment nobody is looking at the numbers.
                        $total = $existing->productRequest->skus()->count() + ($commit ? 0 : $added);

                        $edits = $this->applySheetEdits($existing->productRequest, $data, $commit);

                        if ($edits['applied']) {
                            $fixed[] = implode('; ', $edits['applied']);
                            $result['updated']++;
                        }

                        foreach ($edits['conflicts'] as $conflict) {
                            $result['conflicts']++;
                            $result['log'][] = "{$existing->productRequest->reference} — {$conflict}";
                        }

                        if ($shortfall = $this->countShortfall($data, $total, $sheetCache[$sheetName])) {
                            $result['count_mismatch']++;
                            $result['log'][] = "{$existing->productRequest->reference} — Request No {$requestNo} / {$token} "
                                . "({$data['Brand']}): {$shortfall}";
                        }
                    }

                    if ($fixed) {
                        $result['backfilled']++;
                        $result['log'][] = ($commit ? 'Backfilled' : 'Would backfill')
                            . " Request No {$requestNo} / {$token} (" . implode(', ', $fixed) . ')';
                    } else {
                        $result['skipped_existing']++;
                    }
                    continue;
                }

                if ($this->isIgnoredToken($token)) {
                    $result['ignored']++;
                    continue;
                }

                // Claim this (request no, website token) before doing any work. The
                // unique index is what makes the claim safe: two runs racing on
                // the same row both read no ledger entry a moment ago, and without
                // this both would create a request and the second would silently
                // repoint the ledger at itself, orphaning the first.
                if ($commit && !$existing) {
                    try {
                        $existing = ProductRequestSheetSync::create([
                            'request_no'    => $requestNo,
                            'website_token' => $token,
                            'status'        => 'claimed',
                        ]);
                    } catch (\Illuminate\Database\QueryException) {
                        $result['skipped_existing']++;
                        continue;
                    }
                }

                if (!$deptConfig) {
                    $this->record($requestNo, $token, null, null, 'unmatched_department',
                        "Department \"{$department}\" is not in config/product_request_sync.php");
                    $result['unmatched_department']++;
                    $result['log'][] = "Skipped Request No {$requestNo} / {$token} ({$data['Brand']}): "
                        . "department \"{$department}\" is not in config/product_request_sync.php";
                    continue;
                }

                $store = $this->storeForToken($token);

                if (!$store) {
                    $this->record($requestNo, $token, null, null, 'unmatched_store',
                        "No store mapped for website token \"{$token}\"");
                    $result['unmatched_store']++;
                    $result['log'][] = "Skipped Request No {$requestNo} / {$token} ({$data['Brand']}): "
                        . "website token \"{$token}\" has no store — add it to config/product_request_sync.php";
                    continue;
                }

                $sheetName = $deptConfig['sheet'];

                if (!array_key_exists($sheetName, $sheetCache)) {
                    $sheetCache[$sheetName] = $this->drive->worksheetValues($item['driveId'], $item['itemId'], $sheetName);
                }

                $swapNote = null;
                $skus     = $this->matchSkus($sheetCache[$sheetName], $data['Request Date'] ?? null, (string) ($data['Brand'] ?? ''), $swapNote);

                // Said out loud every time. A request whose SKUs were found only
                // by guessing at a typo is one somebody should glance at.
                if ($swapNote && $skus) {
                    $result['swapped_dates']++;
                    $result['log'][] = "Request No {$requestNo} / {$token} ({$data['Brand']}): {$swapNote}";
                }

                if (empty($skus)) {
                    $missing = $this->missingColumnsOn($sheetCache[$sheetName]);
                    $date    = $this->normalizeExcelDate($data['Request Date'] ?? null)?->toDateString()
                        ?? (string) ($data['Request Date'] ?? 'no date');

                    // A tab that cannot be read at all is worth saying plainly:
                    // every request against it will fail until the header is fixed.
                    $rawDate = trim((string) ($data['Request Date'] ?? ''));

                    $why = $missing
                        ? "the \"{$sheetName}\" tab has no " . implode(' or ', array_map(fn ($c) => "\"{$c}\"", $missing)) . ' column'
                        : "no row in \"{$sheetName}\" matches date {$date} (cell \"{$rawDate}\") + brand \"{$data['Brand']}\""
                            . $this->mismatchHint($sheetCache[$sheetName], (string) ($data['Brand'] ?? ''));

                    $this->record($requestNo, $token, $store->id, null, 'unmatched_skus', ucfirst($why));
                    $result['unmatched_skus']++;
                    $result['log'][] = "Skipped Request No {$requestNo} / {$token} ({$data['Brand']}): {$why}";
                    continue;
                }

                // The sheet states how many SKUs it expects. When fewer match, some
                // rows on the category tab disagree with the master row — a wrong
                // date on one line is invisible otherwise, because the request
                // still imports and simply comes in short.
                if ($shortfall = $this->countShortfall($data, count($skus), $sheetCache[$sheetName])) {
                    $result['count_mismatch']++;
                    $result['log'][] = "Request No {$requestNo} / {$token} ({$data['Brand']}): {$shortfall}";
                }

                if (!$commit) {
                    // Counted, not just logged. A dry run reporting "Created 0"
                    // while listing rows it would create reads as "nothing to do"
                    // — the one thing a dry run must never get wrong.
                    $result['created']++;
                    $result['log'][] = "Would create: Request No {$requestNo} / {$token} → {$store->name}, "
                        . "category={$deptConfig['category']}, brand={$data['Brand']}, " . count($skus) . ' SKUs';
                    continue;
                }

                try {
                    $productRequest = $this->createRequest($data, $store, $deptConfig['category'], $skus, $syncUser);
                    $this->record($requestNo, $token, $store->id, $productRequest->id, 'created', null);
                    $result['created']++;
                    $result['log'][] = "Created {$productRequest->reference} for Request No {$requestNo} / {$token}";
                } catch (\Throwable $e) {
                    $this->record($requestNo, $token, $store->id, null, 'error', $e->getMessage());
                    $result['errors']++;
                    $result['log'][] = "ERROR on Request No {$requestNo} / {$token}: {$e->getMessage()}";
                }
            }
        }

        return $result;
    }

    /**
     * The sheet is hand-typed and inconsistently cased row to row
     * (e.g. "LEATHER GOODS" vs "Leather Goods") — matched on letters only.
     */
    /**
     * Every master row against what is actually in the system.
     *
     * The sync reports exceptions — what it skipped, what disagreed. That answers
     * "did anything go wrong", not "is all of it here", and those are different
     * questions: a row that imported with half its SKUs is not an exception to
     * anything, it is just quietly short.
     *
     * Read-only. It creates and changes nothing.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function audit(): array
    {
        $this->drive->asServiceAccount();

        $item         = $this->drive->resolveShareItem(config('product_request_sync.master_sheet_url'));
        $masterValues = $this->drive->worksheetValues($item['driveId'], $item['itemId'], config('product_request_sync.master_worksheet'));
        $header       = array_map('trim', $masterValues[0] ?? []);

        $sheetCache = [];
        $rows       = [];

        foreach (array_slice($masterValues, 1) as $row) {
            $data      = array_combine($header, array_pad($row, count($header), null));
            $requestNo = (int) ($data['Request No'] ?? 0);

            if (!$requestNo) {
                continue;
            }

            $department = trim((string) ($data['Department'] ?? ''));
            $deptConfig = $this->departmentConfigFor($department);
            $brand      = trim((string) ($data['Brand'] ?? ''));
            $expected   = (int) preg_replace('/[^0-9]/', '', (string) ($data['SKU Count'] ?? ''));
            $listed     = filled($data['Listed By'] ?? null) || filled($data['Listed Date'] ?? null);

            foreach ($this->splitWebsiteTokens((string) ($data['Website'] ?? '')) as $token) {
                $ledger  = ProductRequestSheetSync::where('request_no', $requestNo)->where('website_token', $token)->first();
                $request = $ledger?->productRequest;

                $onTab = ['rows' => 0, 'distinct' => 0];
                $note  = null;

                if ($deptConfig) {
                    $sheetName = $deptConfig['sheet'];

                    if (!array_key_exists($sheetName, $sheetCache)) {
                        try {
                            $sheetCache[$sheetName] = $this->drive->worksheetValues($item['driveId'], $item['itemId'], $sheetName);
                        } catch (\Throwable $e) {
                            $sheetCache[$sheetName] = [];
                            $note = "tab \"{$sheetName}\" could not be read";
                        }
                    }

                    $onTab = $this->rowsOnDateFor($sheetCache[$sheetName], $data['Request Date'] ?? null, $brand);
                }

                $inSystem = $request?->skus()->count() ?? 0;

                $rows[] = [
                    'request_no'  => $requestNo,
                    'website'     => $token,
                    'brand'       => $brand,
                    'department'  => $department,
                    'category'    => $deptConfig['category'] ?? null,
                    'reference'   => $request?->reference,
                    'status'      => $request?->statusLabel(),
                    'sheet_count' => $expected,
                    'tab_rows'    => $onTab['rows'],
                    'tab_distinct'=> $onTab['distinct'],
                    'in_system'   => $inSystem,
                    'verdict'     => $this->auditVerdict($request, $ledger, $token, $deptConfig, $listed, $expected, $onTab, $inSystem, $note),
                ];
            }
        }

        $totals = [
            'rows'         => count($rows),
            'imported'     => 0,
            'short'        => 0,
            'missing'      => 0,
            'not_imported' => 0,
            'ignored'      => 0,
        ];

        foreach ($rows as $entry) {
            $totals[match (true) {
                str_starts_with($entry['verdict'], 'OK')           => 'imported',
                str_starts_with($entry['verdict'], 'SHORT')        => 'short',
                str_starts_with($entry['verdict'], 'MISSING SKUS') => 'missing',
                str_starts_with($entry['verdict'], 'NOT IMPORTED') => 'not_imported',
                default                                            => 'ignored',
            }]++;
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * One line saying where this sheet row stands, prefixed so it can be counted.
     *
     * @param  array{rows: int, distinct: int}  $onTab
     */
    private function auditVerdict(
        ?ProductRequest $request,
        ?ProductRequestSheetSync $ledger,
        string $token,
        ?array $deptConfig,
        bool $listed,
        int $expected,
        array $onTab,
        int $inSystem,
        ?string $note,
    ): string {
        if ($note) {
            return "NOT IMPORTED — {$note}";
        }

        if (!$request) {
            return match (true) {
                $this->isIgnoredToken($token) => "IGNORED — \"{$token}\" is not synced by choice",
                !$deptConfig                  => 'NOT IMPORTED — department not mapped to a category tab',
                !$this->storeForToken($token) => "NOT IMPORTED — no website matches \"{$token}\"",
                $onTab['rows'] === 0          => 'NOT IMPORTED — no rows on the category tab for that date and brand',
                $listed                       => 'IGNORED — already listed by hand, and not marked Completed',
                (bool) $ledger                => 'NOT IMPORTED — ' . ($ledger->note ?: $ledger->status),
                default                       => 'NOT IMPORTED — no reason recorded',
            };
        }

        // The master row counts rows; a request holds distinct SKUs. Where the
        // whole difference is repeats, nothing is missing.
        if ($expected > 0 && $inSystem < $onTab['distinct']) {
            return 'SHORT — ' . ($onTab['distinct'] - $inSystem) . ' SKU(s) on the tab are not on the request';
        }

        if ($expected > $onTab['rows'] && $onTab['rows'] > 0) {
            return 'MISSING SKUS — the master row counts ' . ($expected - $onTab['rows']) . ' more than the tab has';
        }

        return "OK — {$inSystem} SKU(s)";
    }

    private function departmentConfigFor(string $department): ?array
    {
        $map = config('product_request_sync.department_map', []);

        foreach ($map as $key => $config) {
            if (strcasecmp($key, $department) === 0) {
                return $config;
            }
        }

        // The sheet often names the department the way this app names the category
        // — "LUGGAGE" rather than "Travel", "BEAUTY" rather than "Perfumes &
        // Cosmetics". Matching on the category as well means the obvious spelling
        // works without another alias every time somebody types the plain word.
        foreach ($map as $config) {
            if (strcasecmp($config['category'] ?? '', $department) === 0) {
                return $config;
            }
        }

        return null;
    }

    /** Tokens this app is told not to sync — a decision, not a missing store. */
    private function isIgnoredToken(string $token): bool
    {
        foreach (config('product_request_sync.ignored_website_tokens', []) as $ignored) {
            if (strcasecmp($ignored, $token) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The store a website token means.
     *
     * Mapped domains first, then the store names in Settings — so a token the
     * config has never heard of still resolves once somebody creates a store
     * called that, without a deploy.
     */
    private function storeForToken(string $token): ?Store
    {
        foreach (config('product_request_sync.website_store_map', []) as $key => $domain) {
            if (strcasecmp($key, $token) === 0) {
                return Store::where('shopify_domain', $domain)->first();
            }
        }

        // Every store here is named "<something> Website", while the sheet writes
        // the bare name — so "Gold Gourmet" has to find "Gold Gourmet Website".
        $name = strtolower(trim($token));

        foreach ([$name, "{$name} website", preg_replace('/\s*website$/', '', $name)] as $candidate) {
            $store = Store::whereRaw('LOWER(TRIM(name)) = ?', [$candidate])->first();

            if ($store) {
                return $store;
            }
        }

        return null;
    }

    /** "BS - PG-SN" / "BS & Samsonite" / "BS and Gold Gourmet" → ["BS", "PG", "SN"] etc. */
    private function splitWebsiteTokens(string $raw): array
    {
        $normalized = preg_replace('/\s+and\s+/i', ' & ', trim($raw));
        $parts      = preg_split('/\s*[-&\/,]\s*/', (string) $normalized);

        return array_values(array_unique(array_filter(array_map('trim', $parts ?: []))));
    }

    /**
     * The columns that link a category tab back to a master row. A tab missing
     * any of them can never match anything, which is a different problem from a
     * row simply not being there yet — see missingColumnsOn().
     */
    private const SKU_COLUMNS = ['Date', 'Brand Name', 'Item SKU'];

    /**
     * Which of the linking columns a tab does not have.
     *
     * Without this a mis-headed tab looks exactly like a tab whose rows have not
     * been filled in — every request against it is reported as "no row matched",
     * and the person reading that goes looking for the rows rather than at the
     * header.
     *
     * @return array<int, string>
     */
    private function missingColumnsOn(array $sheetValues): array
    {
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $sheetValues[0] ?? []);

        return array_values(array_filter(
            self::SKU_COLUMNS,
            fn ($column) => !in_array(strtolower($column), $header, true),
        ));
    }

    /**
     * Why a row did not match, in the only terms that let somebody fix it.
     *
     * "No row matched date + brand" is true but useless: the brand may be absent
     * from the tab entirely, or present under different dates, or spelled another
     * way — three different jobs. Saying which turns a puzzle into an edit.
     */
    private function mismatchHint(array $sheetValues, string $brand): string
    {
        $header   = array_map('trim', $sheetValues[0] ?? []);
        $dateCol  = array_search('Date', $header, true);
        $brandCol = array_search('Brand Name', $header, true);

        if ($dateCol === false || $brandCol === false) {
            return '';
        }

        $wanted = strtolower(trim($brand));
        $dates  = [];
        $near   = [];

        foreach (array_slice($sheetValues, 1) as $row) {
            $rowBrand = trim((string) ($row[$brandCol] ?? ''));

            if ($rowBrand === '') {
                continue;
            }

            if (strcasecmp($rowBrand, trim($brand)) === 0) {
                $raw = trim((string) ($row[$dateCol] ?? ''));
                $on  = $this->normalizeExcelDate($row[$dateCol] ?? null)?->toDateString();

                if ($on) {
                    $dates[$raw] = $on;
                }

                continue;
            }

            // One name containing the other is the usual near miss — "CERRUTI"
            // against "CERRUTI 1881", or a brand with a suffix on one tab only.
            $other = strtolower($rowBrand);

            if ($wanted !== '' && (str_contains($other, $wanted) || str_contains($wanted, $other))) {
                $near[$rowBrand] = true;
            }
        }

        if ($dates) {
            $shown = array_slice($dates, 0, 4, preserve_keys: true);

            // The raw cell alongside the parsed date: a day/month swap looks like a
            // disagreement between the tabs when it is really this app reading one
            // of them the wrong way round, and only the raw text tells them apart.
            return ' — that brand is on the tab, dated '
                . implode(', ', array_map(
                    fn ($raw, $parsed) => $parsed . ' (cell "' . $raw . '")',
                    array_keys($shown),
                    $shown,
                ))
                . (count($dates) > count($shown) ? ' and others' : '');
        }

        if ($near) {
            return ' — the tab has no such brand, but does have ' . implode(', ', array_slice(array_keys($near), 0, 3));
        }

        return ' — that brand does not appear on the tab at all';
    }

    /** Rows in a category sheet whose Date + Brand Name match this request — the only link between the two tabs. */
    private function matchSkus(array $sheetValues, mixed $requestDateRaw, string $brand, ?string &$note = null): array
    {
        $header   = array_map('trim', $sheetValues[0] ?? []);
        $dateCol  = array_search('Date', $header, true);
        $skuCol   = array_search('Item SKU', $header, true);
        $brandCol = array_search('Brand Name', $header, true);

        if ($dateCol === false || $skuCol === false || $brandCol === false) {
            return [];
        }

        [$targetDate, $swapped] = $this->tabDateFor($sheetValues, $requestDateRaw, $brand);

        if (!$targetDate) {
            return [];
        }

        if ($swapped) {
            $note = "matched on {$targetDate} — day and month are swapped on the tab";
        }

        $skus = [];

        foreach (array_slice($sheetValues, 1) as $row) {
            $rowDate  = $this->normalizeExcelDate($row[$dateCol] ?? null)?->toDateString();
            $rowBrand = trim((string) ($row[$brandCol] ?? ''));

            if ($rowDate === $targetDate && strcasecmp($rowBrand, trim($brand)) === 0) {
                $sku = trim((string) ($row[$skuCol] ?? ''));
                if ($sku !== '') {
                    $skus[] = $sku;
                }
            }
        }

        return array_values(array_unique($skus));
    }

    /**
     * Which date on a category tab holds this request's rows.
     *
     * Normally the master row's date, verbatim. But the tabs are filled in by
     * people using different date settings, so a request dated 9 August is
     * written on the tab as 8 September — the same day typed with the parts
     * transposed. Roughly a third of the unmatched rows are exactly this, and
     * skipping them loses real work over a locale.
     *
     * Guessing is only safe when there is nothing to guess between: the swap is
     * accepted only when that brand appears on exactly one date on the tab and
     * that date is the transposition. A brand spread over several dates is
     * ambiguous and stays skipped, which is why RAGO (5 Aug and 17 Aug) is left
     * for a person to sort out.
     *
     * @return array{0: ?string, 1: bool}  the date to match on, and whether it was a swap
     */
    private function tabDateFor(array $sheetValues, mixed $requestDateRaw, string $brand): array
    {
        $target = $this->normalizeExcelDate($requestDateRaw)?->toDateString();

        if (!$target) {
            return [null, false];
        }

        $dates = $this->brandDates($sheetValues, $brand);

        if (in_array($target, $dates, true)) {
            return [$target, false];
        }

        $swapped = $this->swapDayAndMonth($target);

        if ($swapped !== null && $dates === [$swapped]) {
            return [$swapped, true];
        }

        return [$target, false];
    }

    /**
     * The distinct dates a brand's SKU rows carry on a tab.
     *
     * @return array<int, string>
     */
    private function brandDates(array $sheetValues, string $brand): array
    {
        $header   = array_map('trim', $sheetValues[0] ?? []);
        $dateCol  = array_search('Date', $header, true);
        $brandCol = array_search('Brand Name', $header, true);
        $skuCol   = array_search('Item SKU', $header, true);

        if ($dateCol === false || $brandCol === false || $skuCol === false) {
            return [];
        }

        $dates = [];

        foreach (array_slice($sheetValues, 1) as $row) {
            if (trim((string) ($row[$skuCol] ?? '')) === ''
                || strcasecmp(trim((string) ($row[$brandCol] ?? '')), trim($brand)) !== 0) {
                continue;
            }

            if ($date = $this->normalizeExcelDate($row[$dateCol] ?? null)?->toDateString()) {
                $dates[$date] = true;
            }
        }

        $dates = array_keys($dates);
        sort($dates);

        return $dates;
    }

    /** 2026-08-09 → 2026-09-08. Null when the day is past 12 and cannot be a month. */
    private function swapDayAndMonth(string $date): ?string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        if ($day > 12 || $day < 1 || $month === $day) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $day, $month);
    }

    /** The sheet stores dates as Excel serials in most tabs, but as three different string formats in others. */
    private function normalizeExcelDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value);
        }

        $value = trim((string) $value);

        foreach (['d-m-y', 'd/m/Y', 'M j,Y', 'd-M-y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                // try the next format
            }
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function createRequest(array $data, Store $store, string $category, array $skus, User $syncUser): ProductRequest
    {
        // "Requested By" is hand-typed on the sheet ("KAYCEE", "Nada Rezeg") and
        // usually names someone with no account here, so it is kept verbatim for
        // display; user_id only decides who owns/gets notified about the request.
        $requestedBy = trim((string) ($data['Requested By'] ?? ''));
        $requester   = ($requestedBy !== '' ? User::where('name', $requestedBy)->first() : null) ?? $syncUser;

        $imagesReady = strcasecmp(trim((string) ($data['Images Ready'] ?? '')), 'yes') === 0;
        $published   = $this->sheetSaysPublished($data);
        $priority    = strtolower(trim((string) ($data['Priority'] ?? '')));

        // sub_category/department/collection no longer exist on product_requests
        // (2026_08_10_000008 dropped them as rarely-filled-in) — the sheet's
        // equivalents are kept in notes instead of silently discarded.
        $sheetContext = array_filter([
            'Category'          => trim((string) ($data['Category'] ?? '')),
            'Collection/Season' => trim((string) ($data['Collection/Season'] ?? '')),
        ]);
        $contextLine = collect($sheetContext)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' | ');
        $notes       = trim((string) ($data['Remarks / Notes'] ?? ''));
        $notes       = trim(implode("\n", array_filter([$contextLine, $notes])));

        $productRequest = ProductRequest::create([
            'reference'                 => ProductRequest::nextReference(),
            'sheet_request_no'          => (int) ($data['Request No'] ?? 0) ?: null,
            'sheet_request_date'        => $this->normalizeExcelDate($data['Request Date'] ?? null)?->toDateString(),
            'sheet_requested_by'        => $requestedBy ?: null,
            'user_id'                   => $requester->id,
            'store_id'                  => $store->id,
            'request_type'              => 'new_brand',
            'brand'                     => trim((string) ($data['Brand'] ?? '')),
            'category'                  => $category,
            // The sheet already calls this one done, so it comes in finished
            // rather than starting a workflow for work that has happened.
            'status'                    => $published ? ProductRequest::PUBLISHED : ProductRequest::SUBMITTED,
            'published_at'              => $published ? now() : null,
            'completed_at'              => $published ? now() : null,
            'priority'                  => in_array($priority, ['high', 'medium', 'low'], true) ? $priority : 'medium',
            'online_launch_date'        => $this->normalizeExcelDate($data['Requested Website Go-Live Date'] ?? null)?->toDateString(),
            'image_source'              => $imagesReady ? ProductRequest::IMG_SUPPLIER : ProductRequest::IMG_PHOTOSHOOT,
            'supplier_images_available' => $imagesReady,
            // Left undecided rather than assumed. The sheet not saying the images
            // are ready is not the same as saying a shoot is needed, and treating
            // it as one put every imported request in the Photoshoot Schedule.
            'photoshoot_required'       => false,
            'photoshoot_status'         => null,
            'use_ai_content'            => false,
            'notes'                     => $notes ?: null,
            'validation_status'         => 'pending',
            'total_skus'                => count($skus),
            // What the sheet said now, so a later edit can be told apart from
            // somebody changing the request here.
            'sheet_snapshot'            => $this->sheetValues($data),
        ]);

        $this->mapping->syncSkus($productRequest, $skus);

        $this->workflow->log(
            request:     $productRequest,
            action:      'created',
            description: 'Product request created automatically from the SharePoint tracking sheet',
            toStatus:    $published ? ProductRequest::PUBLISHED : ProductRequest::SUBMITTED,
            remarks:     count($skus) . ' SKUs imported'
                         . ($published ? ' — the sheet marks this Completed, so it is recorded as published' : ''),
        );

        // Same staffing as a request raised by hand: the category's owner takes
        // it, its brand manager holds the brand-side task, and a shoot goes to
        // the photoshoot coordinator. The sheet names nobody, so without this
        // every imported request lands on "nobody assigned yet".
        $this->workflow->staffFromCategory($productRequest, $syncUser, notify: false);

        ValidateProductRequestSkusJob::dispatch($productRequest->id, $requester->id, reconcile: !$published)
            ->onQueue('bulkupload');

        return $productRequest;
    }

    /**
     * The sheet's own word on where a row stands: "Completed" means e-commerce
     * has it live. Anything else — "Missing images", "Product images are not
     * available", blank — is not completion and is left to the workflow.
     *
     * Matched loosely on the header because the column is typed by hand and has
     * appeared as "e-com Status" and "E-Com Status"; matched exactly on the
     * value, so no free-text remark is ever read as finished.
     */
    private function sheetSaysPublished(array $data): bool
    {
        foreach ($data as $header => $value) {
            if (preg_replace('/[^a-z]/', '', strtolower((string) $header)) !== 'ecomstatus') {
                continue;
            }

            return strcasecmp(trim((string) $value), 'completed') === 0;
        }

        return false;
    }

    /**
     * The fields the sheet is allowed to change on a request that already exists.
     *
     * Deliberately short. Category is left out: it decides which tab the SKUs
     * come from and who is staffed, so a change there is reported rather than
     * applied. Notes are left out because the team writes in them.
     */
    private const SHEET_OWNED = ['brand', 'priority', 'online_launch_date'];

    /**
     * What the sheet currently says, in the request's own terms.
     *
     * @return array<string, string|null>
     */
    private function sheetValues(array $data): array
    {
        $priority = strtolower(trim((string) ($data['Priority'] ?? '')));

        return [
            'brand'              => trim((string) ($data['Brand'] ?? '')) ?: null,
            'priority'           => in_array($priority, ['high', 'medium', 'low'], true) ? $priority : null,
            'online_launch_date' => $this->normalizeExcelDate($data['Requested Website Go-Live Date'] ?? null)?->toDateString(),
        ];
    }

    /**
     * The same fields as sheetValues(), read off the request instead.
     *
     * @return array<string, string|null>
     */
    private function requestValues(ProductRequest $request): array
    {
        return [
            'brand'              => trim((string) $request->brand) ?: null,
            'priority'           => $request->priority,
            'online_launch_date' => $request->online_launch_date?->toDateString(),
        ];
    }

    /**
     * Bring a request into line with an edited sheet row.
     *
     * Three-way, against the snapshot taken when the sheet was last read: where
     * the request still holds what the sheet last said, the sheet's new value is
     * applied. Where somebody has changed it here since, theirs wins and the
     * disagreement is reported — a spreadsheet edit must not quietly undo it.
     *
     * A request with no snapshot yet (everything imported before this existed)
     * gets one recorded and nothing changed, so the first run after this is
     * always safe.
     *
     * @return array{applied: array<int, string>, conflicts: array<int, string>}
     */
    private function applySheetEdits(ProductRequest $request, array $data, bool $commit): array
    {
        $sheet    = $this->sheetValues($data);
        $snapshot = $request->sheet_snapshot;

        // Nothing to compare against yet. The snapshot records what the REQUEST
        // holds, not what the sheet says: taking the sheet's values would declare
        // any difference a local edit and then refuse to ever apply it. Assuming
        // the two were in step means the next sheet change is seen as a change.
        if ($snapshot === null) {
            if ($commit) {
                $request->update(['sheet_snapshot' => $this->requestValues($request)]);
            }

            return ['applied' => [], 'conflicts' => []];
        }

        $applied   = [];
        $conflicts = [];
        $updates   = [];

        foreach (self::SHEET_OWNED as $field) {
            $now  = $sheet[$field] ?? null;
            $then = $snapshot[$field] ?? null;

            if ($now === null || $now === $then) {
                continue;   // the sheet has not changed this
            }

            $mine = $field === 'online_launch_date'
                ? $request->online_launch_date?->toDateString()
                : (string) $request->$field;

            if ($mine !== null && $then !== null && $mine !== $then) {
                $conflicts[] = "{$field} is \"{$mine}\" here but the sheet now says \"{$now}\" — left as it is";
                continue;
            }

            $updates[$field] = $now;
            $applied[]       = "{$field}: \"{$then}\" → \"{$now}\"";
        }

        if ($commit && ($updates || $snapshot !== $sheet)) {
            $request->update($updates + ['sheet_snapshot' => $sheet]);

            if ($applied) {
                $this->workflow->log(
                    request:     $request,
                    action:      'sheet_updated',
                    description: 'Updated from the tracking sheet',
                    remarks:     implode('; ', $applied),
                );
            }
        }

        return ['applied' => $applied, 'conflicts' => $conflicts];
    }

    /**
     * How far short of the sheet's own SKU Count the matched rows fall.
     *
     * The master row states the number, so a disagreement is the sheet telling us
     * some of its category rows do not line up — almost always one line carrying a
     * different date from the rest. Without this the request imports quietly short
     * and nobody notices until the SKUs are counted by hand.
     */
    private function countShortfall(array $data, int $matched, array $sheetValues): ?string
    {
        $expected = (int) preg_replace('/[^0-9]/', '', (string) ($data['SKU Count'] ?? ''));

        if ($expected < 1 || $expected === $matched) {
            return null;
        }

        $brand = (string) ($data['Brand'] ?? '');
        $rows  = $this->rowsOnDateFor($sheetValues, $data['Request Date'] ?? null, $brand);

        // The master row counts ROWS; a request holds distinct SKUs. Where the
        // difference is entirely repeats, nothing is missing and saying otherwise
        // trains people to ignore the warning.
        $repeats = $rows['rows'] - $rows['distinct'];
        $absent  = $expected - $rows['rows'];

        if ($absent <= 0 && $repeats > 0) {
            return null;
        }

        if ($absent > 0) {
            return "the sheet says {$expected} SKU(s) but the tab only has {$rows['rows']} row(s) for that date"
                . ($repeats > 0 ? " ({$repeats} of them repeating a SKU, so {$matched} distinct)" : '')
                . ' — ' . $absent . ' row(s) the master row counts are not on the tab'
                . $this->brandBreakdown($sheetValues, $brand);
        }

        if ($rows['rows'] > $expected) {
            return "the sheet says {$expected} SKU(s) but the tab has {$rows['rows']} row(s) for that date"
                . " ({$matched} distinct) — the master row's count is behind the tab";
        }

        return null;
    }

    /**
     * Rows on a category tab for one date and brand, and how many distinct SKUs
     * they amount to.
     *
     * The two are not the same number and the gap is the usual explanation for a
     * request looking short: the same Item SKU listed on several rows becomes one
     * SKU on the request, because that is what a SKU is.
     *
     * @return array{rows: int, distinct: int}
     */
    private function rowsOnDateFor(array $sheetValues, mixed $requestDateRaw, string $brand): array
    {
        $header   = array_map('trim', $sheetValues[0] ?? []);
        $dateCol  = array_search('Date', $header, true);
        $brandCol = array_search('Brand Name', $header, true);
        $skuCol   = array_search('Item SKU', $header, true);

        if ($dateCol === false || $brandCol === false || $skuCol === false) {
            return ['rows' => 0, 'distinct' => 0];
        }

        [$target, ] = $this->tabDateFor($sheetValues, $requestDateRaw, $brand);
        $rows       = 0;
        $seen       = [];

        foreach (array_slice($sheetValues, 1) as $row) {
            $sku = trim((string) ($row[$skuCol] ?? ''));

            if ($sku === '' || strcasecmp(trim((string) ($row[$brandCol] ?? '')), trim($brand)) !== 0) {
                continue;
            }

            if ($this->normalizeExcelDate($row[$dateCol] ?? null)?->toDateString() !== $target) {
                continue;
            }

            $rows++;
            $seen[strtoupper($sku)] = true;
        }

        return ['rows' => $rows, 'distinct' => count($seen)];
    }

    /**
     * How many rows the tab holds for a brand, per date.
     *
     * @return string  a readable tail, or '' when there is nothing to add
     */
    private function brandBreakdown(array $sheetValues, string $brand): string
    {
        $header   = array_map('trim', $sheetValues[0] ?? []);
        $dateCol  = array_search('Date', $header, true);
        $brandCol = array_search('Brand Name', $header, true);
        $skuCol   = array_search('Item SKU', $header, true);

        if ($dateCol === false || $brandCol === false || $skuCol === false) {
            return '';
        }

        $perDate = [];
        $blank   = 0;

        foreach (array_slice($sheetValues, 1) as $row) {
            if (strcasecmp(trim((string) ($row[$brandCol] ?? '')), trim($brand)) !== 0) {
                continue;
            }

            // A row with no SKU cannot become one, and counting it explains part
            // of the gap on its own.
            if (trim((string) ($row[$skuCol] ?? '')) === '') {
                $blank++;
                continue;
            }

            $on            = $this->normalizeExcelDate($row[$dateCol] ?? null)?->toDateString() ?? 'no date';
            $perDate[$on]  = ($perDate[$on] ?? 0) + 1;
        }

        if (!$perDate && !$blank) {
            return '';
        }

        arsort($perDate);
        $shown = array_slice($perDate, 0, 5, preserve_keys: true);

        $parts = [];

        foreach ($shown as $date => $count) {
            $parts[] = "{$date} ({$count})";
        }

        if (count($perDate) > count($shown)) {
            $parts[] = 'and ' . (count($perDate) - count($shown)) . ' more date(s)';
        }

        return ' — that brand has ' . array_sum($perDate) . ' row(s) with a SKU on the tab'
            . ($blank ? " plus {$blank} with none" : '')
            . ', dated: ' . implode(', ', $parts);
    }

    /**
     * Adds SKUs the sheet has gained since this request was created.
     *
     * Additive only, deliberately. A SKU on the request but not on the sheet may
     * have been added by hand, or had its mapping recorded by the brand manager —
     * removing it would throw that away over a spreadsheet edit. So the sheet can
     * grow a request but never shrink one.
     *
     * @return int how many were added
     */
    private function addNewSkus(ProductRequest $request, array $sheetSkus, bool $commit): int
    {
        if (empty($sheetSkus)) {
            return 0;
        }

        $have = $request->skus()->pluck('sku')
            ->map(fn ($sku) => strtoupper(trim($sku)))
            ->all();

        $new = array_values(array_filter(
            $sheetSkus,
            fn ($sku) => !in_array(strtoupper(trim($sku)), $have, true),
        ));

        if (empty($new) || !$commit) {
            return count($new);
        }

        foreach (array_chunk($new, 500) as $chunk) {
            ProductRequestSku::insert(array_map(fn ($sku) => [
                'product_request_id' => $request->id,
                'sku'                => $sku,
                'mapping_status'     => ProductRequest::MAP_PENDING,
                'in_shopify'         => false,
                'created_at'         => now(),
                'updated_at'         => now(),
            ], $chunk));
        }

        $this->mapping->rollUp($request);

        $this->workflow->log(
            request:     $request,
            action:      'skus_added',
            description: count($new) . ' SKU(s) added from the tracking sheet',
            remarks:     'Now ' . $request->fresh()->total_skus . ' in total',
        );

        // The new ones have never been looked for in Shopify, and the stage this
        // request sits at depends on whether they are there.
        ValidateProductRequestSkusJob::dispatch($request->id, null)->onQueue('bulkupload');

        return count($new);
    }

    /**
     * Brings an already-synced request up to date with what the sync knows how to
     * do now: the two sheet-origin columns, and the category staffing that the
     * first version of this importer never applied.
     *
     * Only ever fills what is empty — a column edited in the app, or a role given
     * to someone by hand, is the team's and is left alone.
     *
     * @return array<int, string> what was fixed, empty when there was nothing to do
     */
    private function backfillExisting(ProductRequestSheetSync $existing, array $data, bool $commit): array
    {
        $productRequest = $existing->productRequest;

        if (!$productRequest) {
            return [];
        }

        $fixed   = [];
        $updates = [];

        if (!$productRequest->sheet_request_no) {
            $updates['sheet_request_no'] = $existing->request_no;
            $fixed[] = 'request no';
        }

        $requestedBy = trim((string) ($data['Requested By'] ?? ''));
        if (!filled($productRequest->sheet_requested_by) && $requestedBy !== '') {
            $updates['sheet_requested_by'] = $requestedBy;
            $fixed[] = 'requested by';
        }

        // A mirror of the sheet rather than something anybody edits here, so it
        // follows the sheet whenever it changes — which is exactly what happens
        // when a wrong Request Date is corrected.
        $onSheet = $this->normalizeExcelDate($data['Request Date'] ?? null)?->toDateString();

        if ($onSheet && $productRequest->sheet_request_date?->toDateString() !== $onSheet) {
            $updates['sheet_request_date'] = $onSheet;
            $fixed[] = 'request date';
        }

        // The sheet is where e-commerce records that a request is done, so a row
        // marked Completed after the request was imported has to reach it. Only
        // forwards: a request already published has nothing to change, and one
        // that was cancelled here is a decision the sheet does not overrule.
        if ($this->sheetSaysPublished($data) && !$productRequest->isClosed()) {
            if ($commit) {
                $this->workflow->transition(
                    request: $productRequest,
                    to:      ProductRequest::PUBLISHED,
                    remarks: 'The tracking sheet marks this Completed',
                    force:   true,
                    notify:  false,
                );
            }

            $fixed[] = 'published (Completed on the sheet)';
        }

        // staffFromCategory already skips any role that has someone on it, so an
        // untouched request gets staffed and a half-staffed one keeps its people.
        $unstaffed = $productRequest->currentAssignments()->count() === 0;

        if ($updates && $commit) {
            $productRequest->update($updates);
        }

        if ($unstaffed) {
            // A dry run reports staffing only where there is actually someone to
            // staff it with, so "would backfill (assignments)" never promises a
            // move that --commit cannot make.
            $staffed = $commit
                ? $this->workflow->staffFromCategory($productRequest, null, notify: false)
                : array_filter([$productRequest->categoryOwner()]);

            if ($staffed) {
                $fixed[] = 'assignments';
            }
        }

        return $fixed;
    }

    private function record(int $requestNo, string $token, ?int $storeId, ?int $productRequestId, string $status, ?string $note): void
    {
        ProductRequestSheetSync::updateOrCreate(
            ['request_no' => $requestNo, 'website_token' => $token],
            ['store_id' => $storeId, 'product_request_id' => $productRequestId, 'status' => $status, 'note' => $note],
        );
    }
}
