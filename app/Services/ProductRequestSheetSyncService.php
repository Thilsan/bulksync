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
                    $result['skipped_existing']++;
                    continue;
                }

                if (!$deptConfig) {
                    $this->record($requestNo, $token, null, null, 'unmatched_department',
                        "Department \"{$department}\" is not in config/product_request_sync.php");
                    $result['unmatched_department']++;
                    continue;
                }

                $domain = $this->storeDomainForToken($token);
                $store  = $domain ? Store::where('shopify_domain', $domain)->first() : null;

                if (!$store) {
                    $this->record($requestNo, $token, null, null, 'unmatched_store',
                        "No store mapped for website token \"{$token}\"");
                    $result['unmatched_store']++;
                    continue;
                }

                $sheetName = $deptConfig['sheet'];

                if (!array_key_exists($sheetName, $sheetCache)) {
                    $sheetCache[$sheetName] = $this->drive->worksheetValues($item['driveId'], $item['itemId'], $sheetName);
                }

                $skus = $this->matchSkus($sheetCache[$sheetName], $data['Request Date'] ?? null, (string) ($data['Brand'] ?? ''));

                if (empty($skus)) {
                    $this->record($requestNo, $token, $store->id, null, 'unmatched_skus',
                        "No rows in \"{$sheetName}\" matched this request's date + brand");
                    $result['unmatched_skus']++;
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
        $requester = User::where('name', trim((string) ($data['Requested By'] ?? '')))->first() ?? $syncUser;

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

        ValidateProductRequestSkusJob::dispatch($productRequest->id, $requester->id)->onQueue('bulkupload');

        return $productRequest;
    }

    private function record(int $requestNo, string $token, ?int $storeId, ?int $productRequestId, string $status, ?string $note): void
    {
        ProductRequestSheetSync::updateOrCreate(
            ['request_no' => $requestNo, 'website_token' => $token],
            ['store_id' => $storeId, 'product_request_id' => $productRequestId, 'status' => $status, 'note' => $note],
        );
    }
}
