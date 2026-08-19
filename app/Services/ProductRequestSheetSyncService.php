<?php

namespace App\Services;

use App\Jobs\ValidateProductRequestSkusJob;
use App\Models\ProductRequest;
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
        $syncUser = User::where('email', config('product_request_sync.sync_user_email'))->firstOrFail();
        $this->drive->setUser($syncUser);

        $item = $this->drive->resolveShareItem(config('product_request_sync.master_sheet_url'));

        $masterValues = $this->drive->worksheetValues($item['driveId'], $item['itemId'], config('product_request_sync.master_worksheet'));
        $header       = array_map('trim', $masterValues[0] ?? []);
        $rows         = array_slice($masterValues, 1);

        $result = [
            'created'               => 0,
            'backfilled'            => 0,
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

                    if ($fixed) {
                        $result['backfilled']++;
                        $result['log'][] = ($commit ? 'Backfilled' : 'Would backfill')
                            . " Request No {$requestNo} / {$token} (" . implode(', ', $fixed) . ')';
                    } else {
                        $result['skipped_existing']++;
                    }
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

                $domain = $this->storeDomainForToken($token);
                $store  = $domain ? Store::where('shopify_domain', $domain)->first() : null;

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
                    $why = $missing
                        ? "the \"{$sheetName}\" tab has no " . implode(' or ', array_map(fn ($c) => "\"{$c}\"", $missing)) . ' column'
                        : "no row in \"{$sheetName}\" matches date {$date} + brand \"{$data['Brand']}\"";

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
        foreach (config('product_request_sync.department_map', []) as $key => $config) {
            if (strcasecmp($key, $department) === 0) {
                return $config;
            }
        }

        return null;
    }

    private function storeDomainForToken(string $token): ?string
    {
        foreach (config('product_request_sync.website_store_map', []) as $key => $domain) {
            if (strcasecmp($key, $token) === 0) {
                return $domain;
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
