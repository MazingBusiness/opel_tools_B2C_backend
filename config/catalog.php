<?php

/*
| OPEL catalog configuration.
|
| Live catalog rows come from catalog:sync-b2b (B2B listing API, matched by
| part_no). The 45-product fixture remains for tests. Admin edits live in
| separate *_overrides tables and are merged on read by the models
| (see Models/Concerns/HasCatalogOverride).
*/

return [
    /*
    | Full catalog reset (catalog:reset-seed: delete + reload). Default false.
    | Plain `db:seed` never deletes: it upserts by fixed id. The reset path is
    | refused unless this is true. It is ALSO what you would have to set to reset
    | in production or when overrides / seo_meta / orders exist, because those
    | rows reference catalog ids.
    */
    'seed_allow_reset' => (bool) env('CATALOG_SEED_ALLOW_RESET', false),

    /*
    | Future B2B order handoff (not built yet). Decision (Alok, 2026-10-20):
    | send the price the customer PAID, i.e. order_items.unit_price (the
    | effective price at checkout), together with the RAW B2B identifiers
    | (product / variant ids and part_no; part_no can never be overridden).
    */
    'b2b_handoff' => [
        'price_source' => 'order_item_unit_price',
        'identifiers' => 'raw_b2b',
    ],

    /*
    | B2B full product list used by catalog:sync-b2b: one GET to
    | {b2b_api.base_url}/products/all with the B2B_STOCK_TOKEN bearer token.
    | Products missing from a complete response are unpublished only when the
    | row count is within unpublish_tolerance of the last complete run.
    */
    'b2b' => [
        'products_path' => '/products/all',
        'timeout' => (int) env('B2B_PRODUCTS_TIMEOUT', 120),
        'retry' => (int) env('B2B_PRODUCTS_RETRY', 3),
        'lock_seconds' => (int) env('B2B_PRODUCTS_LOCK_SECONDS', 3600),
        'memory_limit' => env('B2B_PRODUCTS_MEMORY_LIMIT', '512M'),
        'chunk' => 500,
        'unpublish_tolerance' => 0.05,
    ],
];
