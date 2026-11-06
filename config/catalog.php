<?php

/*
| OPEL catalog configuration.
|
| The seeded catalog (database/seeders/data/b2b-air-blowers.json, fixed ids) is
| the B2B base. Admin edits live in separate *_overrides tables and are merged
| on read by the models (see Models/Concerns/HasCatalogOverride).
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
];
