<?php

/*
| Fulfilment branches. Keys match product_warehouses.warehouse_code.
| `short` prefixes sub-order, challan and invoice numbers (SO/KOL/…).
| b2b_warehouse_id is the id the B2B order-item API expects (confirmed by B2B
| 2026-11-22; .env can override). stock_key is the column in the B2B stock API.
| Zoho location id and GSTIN come later.
*/

return [
    'KOLKATA' => [
        'name' => 'Kolkata',
        'short' => 'KOL',
        'state' => 'West Bengal',
        'b2b_warehouse_id' => (int) (env('B2B_WAREHOUSE_ID_KOLKATA') ?: 1),
        'stock_key' => 'kolkata',
    ],
    'DELHI' => [
        'name' => 'Delhi',
        'short' => 'DEL',
        'state' => 'Delhi',
        'b2b_warehouse_id' => (int) (env('B2B_WAREHOUSE_ID_DELHI') ?: 2),
        'stock_key' => 'delhi',
    ],
    'MUMBAI' => [
        'name' => 'Mumbai',
        'short' => 'MUM',
        'state' => 'Maharashtra',
        'b2b_warehouse_id' => (int) (env('B2B_WAREHOUSE_ID_MUMBAI') ?: 6),
        'stock_key' => 'mumbai',
    ],
];
