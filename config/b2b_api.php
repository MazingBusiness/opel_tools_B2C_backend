<?php

/*
| B2B (mazingbusiness) APIs used by B2C fulfilment. The token lives only in
| .env (B2B_STOCK_TOKEN); never log it. Stock lookups fail soft: no token or
| no URL means "stock unavailable", never 0.
*/

return [
    'base_url' => rtrim((string) env('B2B_API_BASE_URL', 'https://mazingbusiness.com/mazing_business_react/api/b2c'), '/'),
    'token' => env('B2B_STOCK_TOKEN'),
    'timeout' => (int) env('B2B_API_TIMEOUT', 5),
    'stock_cache_seconds' => 60,
];
