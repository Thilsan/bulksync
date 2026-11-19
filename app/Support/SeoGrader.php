<?php

namespace App\Support;

use App\Models\SeoAuditItem;

/**
 * The SEO audit's grading rules, for one page at a time.
 *
 * Shared by the full catalogue audit and by the re-check after an AI content
 * push, so a product's "after" score is reached by exactly the rules that gave
 * it its "before" one — a re-check graded differently would report movement
 * that is only the yardstick changing.
 *
 * Duplicate meta titles and descriptions are not graded here: one page cannot
 * know what the rest of the catalogue says. The callers resolve those.
 */
final class SeoGrader
{
    /**
     * Grade one product, as streamProductsForSeoAudit() shapes it. Returns a
     * row ready for insert, with 'issues' still an array.
     */
    public static function product(array $product): array
    {
        $images     = $product['images'] ?? [];
        $imageCount = count($images);
        $missingAlt = count(array_filter($images, fn ($i) => trim((string) ($i['alt'] ?? '')) === ''));
        $tagCount   = count(array_filter((array) ($product['tags'] ?? []), fn ($t) => trim((string) $t) !== ''));

        $row = self::gradeMeta($product, SeoAuditItem::TYPE_PRODUCT);

        if ($imageCount === 0) {
            $row['issues'][] = 'no_images';
        } elseif ($missingAlt > 0) {
            $row['issues'][] = 'missing_alt_text';
        }

        if ($tagCount === 0) {
            $row['issues'][] = 'no_tags';
        }

        // A draft or archived product is not on the storefront, so it is still
        // graded and kept — it will be published one day — but left out of the
        // headline figures, which are a statement about pages a search engine
        // can actually see.
        $row['status']             = ($product['status'] ?? '') ?: SeoAuditItem::STATUS_ACTIVE;
        $row['sku']                = ($product['sku'] ?? null) ? mb_substr((string) $product['sku'], 0, 255) : null;
        $row['image_count']        = min($imageCount, 65535);
        $row['images_missing_alt'] = min($missingAlt, 65535);
        $row['tag_count']          = min($tagCount, 65535);

        return self::finalise($row);
    }

    /**
     * Grade one collection.
     *
     * The image, alt-text and tag checks are left off on purpose rather than
     * passing trivially: a collection has no gallery and no tags, and reporting
     * every one of them as "no images" would bury the products that genuinely
     * have none.
     */
    public static function collection(array $collection): array
    {
        return self::finalise(self::gradeMeta($collection, SeoAuditItem::TYPE_COLLECTION));
    }

    /** Scores a row once every check has had its say — duplicates included. */
    public static function finalise(array $row): array
    {
        $row['issue_count'] = count($row['issues']);
        $row['score']       = SeoAuditItem::scoreFor($row['issues']);

        return $row;
    }

    /**
     * The checks both kinds of page share: the meta fields, the body copy, and
     * whether the visible title says what the thing actually is.
     */
    private static function gradeMeta(array $resource, string $type): array
    {
        $metaTitle = trim((string) ($resource['meta_title'] ?? ''));
        $metaDesc  = trim((string) ($resource['meta_description'] ?? ''));
        $title     = trim((string) ($resource['title'] ?? ''));

        // Shopify's `description` is already the plain-text rendering of
        // body_html, so no tag stripping is needed — only the entities a
        // merchant's copy-paste leaves behind.
        $description = trim(html_entity_decode((string) ($resource['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // mb_strlen, not strlen: an Arabic meta title is well within 60
        // characters while being far past 60 bytes, and byte lengths would
        // report every bilingual page as too long.
        $titleLength = mb_strlen($metaTitle);
        $descLength  = mb_strlen($metaDesc);

        $issues = [];

        if ($metaTitle === '') {
            $issues[] = 'missing_meta_title';
        } elseif ($titleLength > SeoAuditItem::META_TITLE_MAX) {
            $issues[] = 'meta_title_too_long';
        } elseif ($titleLength < SeoAuditItem::META_TITLE_MIN) {
            $issues[] = 'meta_title_too_short';
        }

        if ($metaDesc === '') {
            $issues[] = 'missing_meta_description';
        } elseif ($descLength > SeoAuditItem::META_DESC_MAX) {
            $issues[] = 'meta_description_too_long';
        } elseif ($descLength < SeoAuditItem::META_DESC_MIN) {
            $issues[] = 'meta_description_too_short';
        }

        if (mb_strlen($description) < SeoAuditItem::THIN_DESCRIPTION) {
            $issues[] = 'thin_description';
        }

        // A bare model name — "NOBLETON", "Altra", "BFF" — tells a search engine
        // nothing about what the thing is, and a search engine is the one reader
        // who has not already seen the photograph.
        if ($title !== '' && !preg_match('/\s/u', $title)) {
            $issues[] = 'title_single_word';
        }

        return [
            'resource_type'           => $type,
            // Collections have no status in Shopify and are always reachable.
            'status'                  => null,
            'product_id'              => (string) ($resource['id'] ?? ''),
            'product_title'           => mb_substr($title, 0, 255),
            'handle'                  => mb_substr((string) ($resource['handle'] ?? ''), 0, 255),
            'sku'                     => null,
            'meta_title'              => mb_substr($metaTitle, 0, 512),
            'meta_description'        => mb_substr($metaDesc, 0, 1024),
            'meta_title_length'       => $titleLength,
            'meta_description_length' => $descLength,
            'description_length'      => mb_strlen($description),
            // Kept so the screen can show what Shopify falls back to when no
            // meta description was ever written.
            'description_excerpt'     => mb_substr($description, 0, 200),
            'image_count'             => 0,
            'images_missing_alt'      => 0,
            'tag_count'               => 0,
            'issues'                  => $issues,
        ];
    }
}
