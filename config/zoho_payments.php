<?php

return [
    'account_id' => env('ZOHO_PAYMENT_ACCOUNT_ID'),
    'client_id' => env('ZOHO_PAYMENT_CLIENT_ID'),
    'client_secret' => env('ZOHO_PAYMENT_CLIENT_SECRET'),
    'oauth_redirect_uri' => env('ZOHO_PAYMENT_REDIRECT_URI'),
    'return_url' => env('ZOHO_PAYMENT_RETURN_URL'),
    'accounts_base' => env('ZOHO_PAYMENT_ACCOUNTS_BASE', 'https://accounts.zoho.in'),
    'api_base' => env('ZOHO_PAYMENT_API_BASE', 'https://paymentssandbox.zoho.in/api/v1'),
    'soid_prefix' => env('ZOHO_PAYMENT_SOID_PREFIX', 'zohopaysandbox'),
    'scope' => env('ZOHO_PAYMENT_SCOPE', 'ZohoPaySandbox.payments.CREATE,ZohoPaySandbox.payments.READ'),
];
