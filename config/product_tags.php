<?php

/*
|--------------------------------------------------------------------------
| Product tag taxonomy, per store
|--------------------------------------------------------------------------
|
| The fixed tag sets the AI Content review screen offers, keyed first by the
| store's shopify_domain (the same key config/product_request_sync.php uses),
| then by category, then by product type. These replace the free-form tags
| Gemini used to invent: a store's tag vocabulary is a merchandising
| decision, not something a model should guess at.
|
| Every store runs its own vocabulary — Bluesalon's categories and tags mean
| nothing on Paris Gallery — so a store absent from this file simply gets no
| tag picker, and the review screen says so rather than offering an empty
| dropdown. Adding a store is adding a key here; no code changes.
|
| Within a store, each category has a "base" set applied to every type under
| it, plus the per-type tags. The review screen offers base + type,
| de-duplicated, with every tag pre-checked — unchecking is how you opt out.
|
| Tags are written exactly as they appear in Shopify (casing included), so
| adding one here never creates a near-duplicate of an existing tag.
|
| Shape, for reference while this is still being filled in:
|
|     'store.myshopify.com' => [
|         'Category Name' => [
|             'base'  => ['Tag on every type in this category'],
|             'types' => [
|                 'Type Name' => ['Tag', 'Another tag'],
|             ],
|         ],
|     ],
|
*/

return [

    // Bluesalon. Categories are added one at a time, each derived from that
    // category's own Shopify product export.
    'qatarbluesalon.myshopify.com' => [

    ],

];
