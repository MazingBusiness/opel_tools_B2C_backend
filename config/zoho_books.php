<?php

// Zoho Books (customer contacts). Separate OAuth client/refresh token from Zoho Payments.
// Sync is off until every value below is set. Values live in .env only.
return [
    'organization_id' => env('ZOHO_BOOKS_ORGANIZATION_ID'),
    'client_id' => env('ZOHO_BOOKS_CLIENT_ID'),
    'client_secret' => env('ZOHO_BOOKS_CLIENT_SECRET'),
    // Optional fallback. The connect flow (php artisan zoho:books-connect) stores the token encrypted in the database, which wins.
    'refresh_token' => env('ZOHO_BOOKS_REFRESH_TOKEN'),
    // Must match the Authorized Redirect URI in the Zoho API Console exactly.
    'redirect_uri' => env('ZOHO_BOOKS_REDIRECT_URI', 'https://api.opeltools.com/api/v1/zoho/books/oauth/callback'),
    'scopes' => 'ZohoBooks.contacts.CREATE,ZohoBooks.contacts.UPDATE,ZohoBooks.contacts.READ,ZohoBooks.settings.READ',
    // API name of the unique "Party Code" contact custom field, e.g. cf_party_code.
    'party_code_field' => env('ZOHO_BOOKS_PARTY_CODE_FIELD'),
    'accounts_base' => env('ZOHO_BOOKS_ACCOUNTS_BASE', 'https://accounts.zoho.in'),
    'api_base' => env('ZOHO_BOOKS_API_BASE', 'https://www.zohoapis.in/books/v3'),
];
