<?php

namespace App\Support;

use App\Models\Store;

/**
 * The fixed tag vocabulary a store offers on the AI Content review screen.
 *
 * Every store merchandises differently — Bluesalon's "Women Clothing →
 * Blazers" tag set means nothing on another website — so the taxonomy is
 * looked up per store rather than shared. A store with no entry in
 * config/product_tags.php gets an empty array, and the review screen tells
 * the user so instead of offering a dropdown with nothing in it.
 */
class ProductTagTaxonomy
{
    public static function forStore(?Store $store): array
    {
        $domain = strtolower(trim((string) ($store?->shopify_domain ?? '')));

        if ($domain === '') {
            return [];
        }

        // Not config('product_tags.' . $domain): the key holds dots, which
        // config() would read as nesting.
        $taxonomy = config('product_tags', [])[$domain] ?? [];

        return is_array($taxonomy) ? $taxonomy : [];
    }
}
