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
    | B2B product listing used by catalog:sync-b2b. Set these on the server;
    | do not put secrets in chat. Pagination always sends page (+ user_id when
    | set) and never follows next_page_url.
    */
    'b2b' => [
        'products_url' => env('B2B_PRODUCTS_URL'),
        'products_user_id' => env('B2B_PRODUCTS_USER_ID'),
        'retry' => (int) env('B2B_PRODUCTS_RETRY', 3),
        'lock_seconds' => (int) env('B2B_PRODUCTS_LOCK_SECONDS', 10800),
    ],
];
