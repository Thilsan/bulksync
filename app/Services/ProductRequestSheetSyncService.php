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
            'ignored'               => 0,
            'unmatched_store'       => 0,
            'unmatched_department'  => 0,
            'unmatched_skus'        => 0,
            'errors'                => 0,
            'skipped_existing'      => 0,
            'log'                   => [],
        ];

        $sheetCache = [];

        foreach ($rows as $row) {
            $data = array_combine($header, array_pad($row, count($header), null));

            $requestNo = (int) ($data['Request No'] ?? 0);
            if (!$requestNo) {
                continue;
            }

            // Already actioned before this automation existed — not ours to touch.
            if (filled($data['Listed By'] ?? null) || filled($data['Listed Date'] ?? null)) {
                continue;
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

                        $added = $this->addNewSkus(
                            $existing->productRequest,
                            $this->matchSkus($sheetCache[$sheetName], $data['Request Date'] ?? null, (string) ($data['Brand'] ?? '')),
                            $commit,
                        );

                        if ($added > 0) {
                            $fixed[] = "{$added} new SKU(s)";
                            $result['skus_added'] += $added;
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

                $skus = $this->matchSkus($sheetCache[$sheetName], $data['Request Date'] ?? null, (string) ($data['Brand'] ?? ''));

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

                if (!$commit) {
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
    private function matchSkus(array $sheetValues, mixed $requestDateRaw, string $brand): array
    {
        $header   = array_map('trim', $sheetValues[0] ?? []);
        $dateCol  = array_search('Date', $header, true);
        $skuCol   = array_search('Item SKU', $header, true);
        $brandCol = array_search('Brand Name', $header, true);

        if ($dateCol === false || $skuCol === false || $brandCol === false) {
            return [];
        }

        $targetDate = $this->normalizeExcelDate($requestDateRaw)?->toDateString();
        if (!$targetDate) {
            return [];
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
            'sheet_requested_by'        => $requestedBy ?: null,
            'user_id'                   => $requester->id,
            'store_id'                  => $store->id,
            'request_type'              => 'new_brand',
            'brand'                     => trim((string) ($data['Brand'] ?? '')),
            'category'                  => $category,
            'status'                    => ProductRequest::SUBMITTED,
            'priority'                  => in_array($priority, ['high', 'medium', 'low'], true) ? $priority : 'medium',
            'online_launch_date'        => $this->normalizeExcelDate($data['Requested Website Go-Live Date'] ?? null)?->toDateString(),
            'image_source'              => $imagesReady ? ProductRequest::IMG_SUPPLIER : ProductRequest::IMG_PHOTOSHOOT,
            'supplier_images_available' => $imagesReady,
            'photoshoot_required'       => !$imagesReady,
            'photoshoot_status'         => !$imagesReady ? ProductRequest::SHOOT_PENDING : null,
            'use_ai_content'            => false,
            'notes'                     => $notes ?: null,
            'validation_status'         => 'pending',
            'total_skus'                => count($skus),
        ]);

        $this->mapping->syncSkus($productRequest, $skus);

        $this->workflow->log(
            request:     $productRequest,
            action:      'created',
            description: 'Product request created automatically from the SharePoint tracking sheet',
            toStatus:    ProductRequest::SUBMITTED,
            remarks:     count($skus) . ' SKUs imported',
        );

        // Same staffing as a request raised by hand: the category's owner takes
        // it, its brand manager holds the brand-side task, and a shoot goes to
        // the photoshoot coordinator. The sheet names nobody, so without this
        // every imported request lands on "nobody assigned yet".
        $this->workflow->staffFromCategory($productRequest, $syncUser, notify: false);

        ValidateProductRequestSkusJob::dispatch($productRequest->id, $requester->id)->onQueue('bulkupload');

        return $productRequest;
    }

    /**
     * Adds SKUs the sheet has gained since this request was created.
     *
     * Additive only, deliberately. A SKU on the request but not on the sheet may
     * have been added by hand, or had its mapping recorded by Supply Chain —
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
