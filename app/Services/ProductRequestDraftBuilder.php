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
 * The category tabs are not consistent with each other, so rather than insisting
 * on one set of column names it takes the whole row and maps what it recognises
 * (see config/product_request_draft.php), reporting which column it used for
 * each field. Everything it could not place is kept against the variant and
 * shown on the review screen — a column nobody told us about is still worth
 * having in front of the person checking the product.
 *
 * Nothing here talks to Shopify. It produces reviewable rows; pushing them is a
 * separate, deliberate step.
 */
class ProductRequestDraftBuilder
{
    public function __construct(
        private OneDriveService $drive,
    ) {}

    /**
     * @return array{built: int, variants: int, skipped_existing: int, missing_from_sheet: array<int, string>, columns: array{used: array<string, string>, loose: array<int, string>, missing: array<int, string>, ignored: array<int, string>}}
     */
    public function build(ProductRequest $request): array
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

        $empty = ['used' => [], 'loose' => [], 'missing' => [], 'ignored' => []];

        if (empty($missing)) {
            return ['built' => 0, 'variants' => 0, 'skipped_existing' => 0, 'missing_from_sheet' => [], 'columns' => $empty];
        }

        [$rows, $columns] = $this->sheetRowsFor($worksheet, $missing);

        // A SKU the sheet has no row for cannot be invented — it is reported so
        // whoever asked can go and add it rather than wonder where it went.
        $found            = array_map(fn ($r) => $this->normalizeSku($r['fields']['sku']), $rows);
        $missingFromSheet = array_values(array_diff(array_map([$this, 'normalizeSku'], $missing), $found));

        // A rebuild re-reads the sheet, so the drafts it made last time go — that
        // is what rebuilding means, and without it a change to how products are
        // built leaves the old ones alongside the new and every SKU appears
        // twice. What the team has pushed or corrected is theirs, and stays.
        $request->draftProducts()
            ->where('push_status', ProductRequestDraftProduct::PENDING)
            ->whereNull('edited_at')
            ->delete();

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
            'columns'            => $columns,
        ];
    }

    /**
     * Records which of a request's SKUs the sheet carries copy for.
     *
     * The sheet is the brand team's own input, so a blank Description column is
     * the answer to "will copy arrive?" — no. That is what the request needs in
     * order to offer generating it, and it cannot be inferred from Shopify: a
     * product can be live with no description and still have copy waiting on the
     * sheet, or vice versa.
     *
     * @return array{checked: int, with: int, without: int, missing_from_sheet: array<int, string>, column: string|null}
     */
    public function syncSheetDescriptions(ProductRequest $request): array
    {
        $worksheet = $this->worksheetFor($request);

        if (!$worksheet) {
            throw new \RuntimeException(
                "No sheet tab is configured for the \"{$request->category}\" category — see config/product_request_sync.php."
            );
        }

        $skus = $request->skus()->orderBy('id')->pluck('sku')->all();

        if (empty($skus)) {
            return ['checked' => 0, 'with' => 0, 'without' => 0, 'missing_from_sheet' => [], 'column' => null];
        }

        [$rows, $columns] = $this->sheetRowsFor($worksheet, $skus);

        // No Description column at all means the sheet cannot answer the question.
        // Saying so beats marking every SKU as having no copy, which would offer to
        // generate over descriptions that may be sitting in a column we misread.
        if (!isset($columns['used']['body_html'])) {
            throw new \RuntimeException(
                "The \"{$worksheet}\" tab has no description column that this app recognises. "
                . 'It has: ' . implode(', ', array_slice($columns['ignored'], 0, 15))
                . '. Add the right name to config/product_request_draft.php.'
            );
        }

        $with = $without = 0;
        $seen = [];

        foreach ($rows as $row) {
            $sku  = $this->normalizeSku($row['fields']['sku']);
            $copy = filled(trim(strip_tags((string) ($row['fields']['body_html'] ?? ''))));

            $request->skus()->whereRaw('UPPER(TRIM(sku)) = ?', [$sku])->update([
                'sheet_has_description' => $copy,
                'sheet_checked_at'      => now(),
            ]);

            $seen[] = $sku;
            $copy ? $with++ : $without++;
        }

        return [
            'checked'            => count($seen),
            'with'               => $with,
            'without'            => $without,
            'missing_from_sheet' => array_values(array_diff(array_map([$this, 'normalizeSku'], $skus), $seen)),
            'column'             => $columns['used']['body_html'],
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
     * Work out which column on this tab holds each field.
     *
     * Exact names first, in the order they are configured, then a substring
     * match as a last resort. A loose match is reported separately: it is a
     * guess, and the person reviewing should see which column it landed on.
     *
     * @return array{index: array<string, int>, report: array{used: array<string, string>, loose: array<int, string>, missing: array<int, string>, ignored: array<int, string>}}
     */
    private function resolveColumns(array $header): array
    {
        $normalized = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $index  = [];
        $used   = [];
        $loose  = [];
        $absent = [];
        $taken  = [];

        foreach (config('product_request_draft.column_map', []) as $field => $names) {
            $position = null;

            foreach ((array) $names as $name) {
                $found = array_search(strtolower(trim($name)), $normalized, true);

                if ($found !== false && !in_array($found, $taken, true)) {
                    $position = $found;
                    break;
                }
            }

            if ($position !== null) {
                $index[$field] = $position;
                $used[$field]  = trim((string) $header[$position]);
                $taken[]       = $position;
                continue;
            }

            // Nothing named right — try the substring hints for this field only.
            foreach (config("product_request_draft.column_contains.{$field}", []) as $needle) {
                foreach ($normalized as $at => $columnName) {
                    if (in_array($at, $taken, true) || !str_contains($columnName, strtolower($needle))) {
                        continue;
                    }

                    $index[$field] = $at;
                    $used[$field]  = trim((string) $header[$at]);
                    $loose[]       = $field . ' ← "' . trim((string) $header[$at]) . '"';
                    $taken[]       = $at;
                    break 2;
                }
            }

            if (!isset($index[$field])) {
                $absent[] = $field;
            }
        }

        // Columns the tab has that no field claimed. Not a problem — they are
        // kept on the variant either way — but worth naming, because one of them
        // is usually the price nobody could find.
        $ignored = [];

        foreach ($header as $at => $name) {
            if (!in_array($at, $taken, true) && trim((string) $name) !== '') {
                $ignored[] = trim((string) $name);
            }
        }

        return [
            'index'  => $index,
            'report' => ['used' => $used, 'loose' => $loose, 'missing' => $absent, 'ignored' => $ignored],
        ];
    }

    /**
     * Rows on the category tab for the given SKUs — both the mapped fields and
     * the whole row as the sheet has it.
     *
     * @return array{0: array<int, array{fields: array<string, string|null>, raw: array<string, string|null>}>, 1: array<string, mixed>}
     */
    private function sheetRowsFor(string $worksheet, array $skus): array
    {
        // Always the sheet's own connection, never the person clicking. The sheet
        // lives in one account's OneDrive, so reading it as whoever happens to be
        // looking would work for them and fail for everybody else — and it has its
        // own Azure app precisely so it does not depend on anyone's login here.
        $this->drive->asServiceAccount();

        $item   = $this->drive->resolveShareItem(config('product_request_sync.master_sheet_url'));
        $values = $this->drive->worksheetValues($item['driveId'], $item['itemId'], $worksheet);

        $header   = $values[0] ?? [];
        $resolved = $this->resolveColumns($header);
        $index    = $resolved['index'];

        if (!isset($index['sku'])) {
            throw new \RuntimeException(
                "The \"{$worksheet}\" tab has no SKU column — it has: "
                . implode(', ', array_filter(array_map(fn ($h) => trim((string) $h), $header)))
                . '. Add the right name to config/product_request_draft.php.'
            );
        }

        $wanted = array_flip(array_map([$this, 'normalizeSku'], $skus));
        $rows   = [];

        foreach (array_slice($values, 1) as $row) {
            $sku = $this->normalizeSku($row[$index['sku']] ?? '');

            if ($sku === '' || !isset($wanted[$sku])) {
                continue;
            }

            $raw = [];

            foreach ($header as $at => $name) {
                $name = trim((string) $name);

                if ($name !== '') {
                    $raw[$name] = $this->cell($row[$at] ?? null);
                }
            }

            $rows[] = [
                'fields' => collect($index)->map(fn ($at) => $this->cell($row[$at] ?? null))->all(),
                'raw'    => $raw,
            ];
        }

        return [$rows, $resolved['report']];
    }

    /**
     * One product per style code and colour, with a variant per size.
     *
     * The colour belongs in the product, not only in the variant: the team's own
     * Shopify export puts it in the handle
     * ("10219710-triumph-body-make-up-illusion-lace-wp-bra-nude-beige"), and a
     * handle is one product in Shopify — so two colours sharing a style code are
     * two products, each with its sizes as variants.
     *
     * Without a style code the SKU stands alone rather than being folded into
     * someone else's product on a guess.
     *
     * @return array<string, array<string, mixed>>
     */
    private function groupIntoProducts(array $rows, ProductRequest $request): array
    {
        $optionNames = config('product_request_draft.option_names', []);
        $weightUnit  = config('product_request_draft.weight_unit', 'kg');
        $products    = [];
        $handleOwner = [];   // handle => the group that claimed it

        foreach ($rows as $row) {
            $fields    = $row['fields'];
            $sku       = $this->normalizeSku($fields['sku']);
            $styleCode = $fields['style_code'] ?? null;
            $colour    = $fields['option1_value'] ?? null;
            $vendor    = $fields['brand'] ?? $request->brand;
            $title     = $fields['title'] ?? null;

            $key    = filled($styleCode)
                ? 'style:' . strtoupper($styleCode) . '|colour:' . strtoupper((string) $colour)
                : 'sku:' . $sku;
            $handle = $this->handleFor($vendor ?: $request->brand, $title, $colour, $sku, $key, $handleOwner);

            if (!isset($products[$handle])) {
                $products[$handle] = [
                    'style_code'   => filled($styleCode) ? $styleCode : null,
                    // Falls back to something a human can recognise on the review
                    // screen rather than an empty cell.
                    'title'        => $fields['title'] ?? trim(($vendor ?: $request->brand) . ' ' . (filled($styleCode) ? $styleCode : $sku)),
                    'body_html'    => $fields['body_html'] ?? null,
                    'vendor'       => $vendor ?: $request->brand,
                    'product_type' => $fields['product_type'] ?? $request->category,
                    'tags'         => $fields['tags'] ?? null,
                    'image_src'    => $fields['image_src'] ?? null,
                    'option_names' => $this->optionNamesFor($fields, $optionNames),
                    'variants'     => [],
                ];
            }

            $products[$handle]['variants'][] = [
                'sku'              => $sku,
                'option1_value'    => $fields['option1_value'] ?? null,
                'option2_value'    => $fields['option2_value'] ?? null,
                'option3_value'    => $fields['option3_value'] ?? null,
                'price'            => $this->decimal($fields['price'] ?? null),
                'compare_at_price' => $this->decimal($fields['compare_at_price'] ?? null),
                'barcode'          => $this->barcode($fields['barcode'] ?? null),
                'weight'           => $this->decimal($fields['weight'] ?? null),
                'weight_unit'      => $weightUnit,
                'inventory_qty'    => (int) $this->decimal($fields['inventory_qty'] ?? null),
                'image_src'        => $fields['image_src'] ?? null,
                // Everything the sheet had for this SKU, mapped or not.
                'sheet_row'        => $row['raw'],
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

    /**
     * The handle is the product title.
     *
     * A handle is a product's identity in Shopify, so two different products can
     * never share one. Where two would — the same title in two colours — the
     * colour is added, which is what the team's own export does
     * ("...-wp-bra-nude-beige"), and the SKU only as a last resort. Nothing is
     * added when there is nothing to separate, so a plain title stays plain.
     *
     * @param  array<string, string>  $handleOwner  handle => group key, by reference
     */
    private function handleFor(
        string $vendor,
        ?string $title,
        ?string $colour,
        string $sku,
        string $key,
        array &$handleOwner,
    ): string {
        // Nothing usable on the row — fall back to something guaranteed unique.
        $handle = Str::slug((string) $title) ?: Str::slug("{$vendor} {$sku}");

        $taken = fn ($candidate) => ($handleOwner[$candidate] ?? $key) !== $key;

        if ($taken($handle) && filled($colour)) {
            $handle = Str::slug("{$title} {$colour}");
        }

        if ($taken($handle)) {
            $handle = Str::slug("{$title} {$sku}");
        }

        $handleOwner[$handle] = $key;

        return $handle;
    }

    /** Only the options this row actually carries a value for, in order. */
    private function optionNamesFor(array $fields, array $configured): array
    {
        $names = [];

        foreach ([1, 2, 3] as $position) {
            $name = $configured[$position - 1] ?? null;

            if ($name && filled($fields["option{$position}_value"] ?? null)) {
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

    /**
     * Excel writes long barcodes as '7613109523528 to stop itself turning them
     * into scientific notation. That apostrophe is Excel's, not the barcode's.
     */
    private function barcode(?string $barcode): ?string
    {
        return $barcode === null ? null : (ltrim($barcode, "'") ?: null);
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
