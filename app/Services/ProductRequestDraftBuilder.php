<?php

namespace App\Services;

use App\Models\ProductRequest;
use App\Models\ProductRequestDraftProduct;
use App\Models\ProductRequestDraftVariant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Builds Shopify draft products for the SKUs a request could not find in
 * Shopify, using the product information on the tracking sheet's category tab.
 *
 * Only for websites that do not go through Cegid mapping: where they do, an
 * unmatched SKU is Supply Chain's to resolve, not a product to invent.
 *
 * Nothing here talks to Shopify. It produces reviewable rows; pushing them is a
 * separate, deliberate step. See config/product_request_draft.php for the
 * sheet-column mapping this depends on.
 */
class ProductRequestDraftBuilder
{
    public function __construct(
        private OneDriveService $drive,
    ) {}

    /**
     * @return array{built: int, variants: int, skipped_existing: int, missing_from_sheet: array<int, string>}
     */
    public function build(ProductRequest $request, ?User $asUser = null): array
    {
        if ($request->requiresMapping()) {
            throw new \RuntimeException(
                "{$request->store?->name} maps its SKUs in Cegid — unmatched SKUs there go to Supply Chain, not into a draft product."
            );
        }

        $worksheet = $this->worksheetFor($request);

        if (!$worksheet) {
            throw new \RuntimeException(
                "No sheet tab is configured for the \"{$request->category}\" category — see config/product_request_sync.php."
            );
        }

        $missing = $request->skus()
            ->where('in_shopify', false)
            ->orderBy('id')
            ->pluck('sku')
            ->all();

        if (empty($missing)) {
            return ['built' => 0, 'variants' => 0, 'skipped_existing' => 0, 'missing_from_sheet' => []];
        }

        $rows = $this->sheetRowsFor($worksheet, $missing, $asUser);

        // A SKU the sheet has no row for cannot be invented — it is reported so
        // whoever asked can go and add it rather than wonder where it went.
        $found             = array_map(fn ($r) => $this->normalizeSku($r['sku']), $rows);
        $missingFromSheet  = array_values(array_diff(array_map([$this, 'normalizeSku'], $missing), $found));

        $existingHandles = $request->draftProducts()->pluck('handle')->all();

        $built    = 0;
        $variants = 0;
        $skipped  = 0;

        foreach ($this->groupIntoProducts($rows, $request) as $handle => $product) {
            // A draft already on the screen may have been corrected by hand;
            // rebuilding must not throw that away.
            if (in_array($handle, $existingHandles, true)) {
                $skipped++;
                continue;
            }

            $draft = ProductRequestDraftProduct::create([
                'product_request_id' => $request->id,
                'handle'             => $handle,
                'style_code'         => $product['style_code'],
                'title'              => $product['title'],
                'body_html'          => $product['body_html'],
                'vendor'             => $product['vendor'],
                'product_type'       => $product['product_type'],
                'tags'               => $product['tags'],
                'option1_name'       => $product['option_names'][0] ?? null,
                'option2_name'       => $product['option_names'][1] ?? null,
                'option3_name'       => $product['option_names'][2] ?? null,
                'image_src'          => $product['image_src'],
            ]);

            foreach ($product['variants'] as $variant) {
                ProductRequestDraftVariant::create($variant + ['draft_product_id' => $draft->id]);
                $variants++;
            }

            $built++;
        }

        return [
            'built'              => $built,
            'variants'           => $variants,
            'skipped_existing'   => $skipped,
            'missing_from_sheet' => $missingFromSheet,
        ];
    }

    /** The category tab this request's SKUs live on, via the sync's department map. */
    private function worksheetFor(ProductRequest $request): ?string
    {
        foreach (config('product_request_sync.department_map', []) as $config) {
            if (strcasecmp($config['category'] ?? '', (string) $request->category) === 0) {
                return $config['sheet'] ?? null;
            }
        }

        return null;
    }

    /**
     * Rows on the category tab for the given SKUs, with the sheet's columns
     * already renamed to the field names the rest of this class uses.
     *
     * @return array<int, array<string, string|null>>
     */
    private function sheetRowsFor(string $worksheet, array $skus, ?User $asUser): array
    {
        // Read as whoever asked, when they have OneDrive connected — otherwise the
        // configured sync account, the same one the sheet importer uses. Without
        // this, anyone who has not linked OneDrive gets a token error instead of
        // their drafts.
        $reader = ($asUser && $asUser->has_onedrive)
            ? $asUser
            : User::where('email', config('product_request_sync.sync_user_email'))->firstOrFail();

        $this->drive->setUser($reader);

        $item   = $this->drive->resolveShareItem(config('product_request_sync.master_sheet_url'));
        $values = $this->drive->worksheetValues($item['driveId'], $item['itemId'], $worksheet);

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $values[0] ?? []);
        $map    = config('product_request_draft.column_map', []);

        // field => column index on this tab, for the columns it actually has.
        $index = [];

        foreach ($map as $field => $columnName) {
            if (!$columnName) {
                continue;
            }

            $position = array_search(strtolower(trim($columnName)), $header, true);

            if ($position !== false) {
                $index[$field] = $position;
            }
        }

        if (!isset($index['sku'])) {
            throw new \RuntimeException(
                "The \"{$worksheet}\" tab has no \"" . ($map['sku'] ?? 'Item SKU') . '" column — '
                . 'check config/product_request_draft.php against the sheet.'
            );
        }

        $wanted = array_flip(array_map([$this, 'normalizeSku'], $skus));
        $rows   = [];

        foreach (array_slice($values, 1) as $row) {
            $sku = $this->normalizeSku($row[$index['sku']] ?? '');

            if ($sku === '' || !isset($wanted[$sku])) {
                continue;
            }

            $rows[] = collect($index)
                ->map(fn ($position) => $this->cell($row[$position] ?? null))
                ->all();
        }

        return $rows;
    }

    /**
     * SKUs sharing a style code become one product with a variant each. Without
     * a style code the SKU stands alone rather than being folded into someone
     * else's product on a guess.
     *
     * @return array<string, array<string, mixed>>
     */
    private function groupIntoProducts(array $rows, ProductRequest $request): array
    {
        $optionNames = config('product_request_draft.option_names', []);
        $weightUnit  = config('product_request_draft.weight_unit', 'kg');
        $products    = [];

        foreach ($rows as $row) {
            $sku       = $this->normalizeSku($row['sku']);
            $styleCode = $row['style_code'] ?? null;
            $vendor    = $row['brand'] ?? $request->brand;
            $key       = filled($styleCode) ? "style:{$styleCode}" : "sku:{$sku}";
            $handle    = Str::slug(($vendor ?: $request->brand) . '-' . (filled($styleCode) ? $styleCode : $sku));

            if (!isset($products[$handle])) {
                $products[$handle] = [
                    'style_code'   => filled($styleCode) ? $styleCode : null,
                    // Falls back to something a human can recognise on the review
                    // screen rather than an empty cell.
                    'title'        => $row['title'] ?? trim(($vendor ?: $request->brand) . ' ' . (filled($styleCode) ? $styleCode : $sku)),
                    'body_html'    => $row['body_html'] ?? null,
                    'vendor'       => $vendor ?: $request->brand,
                    'product_type' => $row['product_type'] ?? $request->category,
                    'tags'         => $row['tags'] ?? null,
                    'image_src'    => $row['image_src'] ?? null,
                    'option_names' => $this->optionNamesFor($row, $optionNames),
                    'variants'     => [],
                    '_key'         => $key,
                ];
            }

            $products[$handle]['variants'][] = [
                'sku'              => $sku,
                'option1_value'    => $row['option1_value'] ?? null,
                'option2_value'    => $row['option2_value'] ?? null,
                'option3_value'    => $row['option3_value'] ?? null,
                'price'            => $this->decimal($row['price'] ?? null),
                'compare_at_price' => $this->decimal($row['compare_at_price'] ?? null),
                'barcode'          => $row['barcode'] ?? null,
                'weight'           => $this->decimal($row['weight'] ?? null),
                'weight_unit'      => $weightUnit,
                'inventory_qty'    => (int) ($row['inventory_qty'] ?? 0),
                'image_src'        => $row['image_src'] ?? null,
            ];
        }

        // A single-variant product with no option value still needs one, or
        // Shopify names it "Title / Default Title" on the storefront.
        foreach ($products as &$product) {
            if (empty($product['option_names'])) {
                $product['option_names'] = ['Title'];

                foreach ($product['variants'] as $i => $variant) {
                    if (blank($variant['option1_value'])) {
                        $product['variants'][$i]['option1_value'] = 'Default Title';
                    }
                }
            }
        }

        return $products;
    }

    /** Only the options this row actually carries a value for, in order. */
    private function optionNamesFor(array $row, array $configured): array
    {
        $names = [];

        foreach ([1, 2, 3] as $position) {
            $name = $configured[$position - 1] ?? null;

            if ($name && filled($row["option{$position}_value"] ?? null)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function cell(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /** Sheet prices arrive as "QAR 1,250.00" as often as as a number. */
    private function decimal(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', $value);

        return ($clean === '' || !is_numeric($clean)) ? null : (float) $clean;
    }

    private function normalizeSku(mixed $sku): string
    {
        return strtoupper(trim((string) $sku));
    }
}
