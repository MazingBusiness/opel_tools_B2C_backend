<?php

/*
| OPEL SEO v0 configuration.
| STOREFRONT_URL is the public shop origin (no trailing slash). All canonical,
| og:url, breadcrumb and sitemap URLs are built from it.
*/

return [
    'storefront_url' => rtrim((string) env('STOREFRONT_URL', env('FRONTEND_URL', 'http://localhost:5173')), '/'),

    'site_name' => env('SEO_SITE_NAME', 'OPEL Tools'),

    'title_suffix' => ' | OPEL Tools',

    'default_description' => 'Shop power tools, hand tools and workshop equipment online at OPEL Tools. Genuine products, fast delivery across India.',

    'default_og_image' => env('SEO_DEFAULT_OG_IMAGE'),

    'currency' => 'INR',

    // Public payload cache (seconds). Admin saves bump a version key.
    // Keys are per RESOLVED target (type:id) + one shared key for all private
    // routes; unknown/404 paths are never cached.
    'cache_ttl' => (int) env('SEO_CACHE_TTL', 300),

    /*
    | Master switch. false (e.g. staging) → robots.txt "Disallow: /" and every
    | payload is forced noindex,nofollow. Keep true in production.
    */
    'allow_indexing' => (bool) env('SEO_ALLOW_INDEXING', true),

    /*
    | Category / category-group URLs in sitemap.xml. Off until the storefront
    | category pages render real catalog data (they still use mock data).
    */
    'sitemap_categories' => (bool) env('SEO_SITEMAP_CATEGORIES', false),

    // Sitemap protocol limit per file (a sitemap index is a follow-up).
    'sitemap_max_urls' => 50000,

    /*
    | Rate limits for GET /api/v1/seo (requests per minute).
    | Browsers/unknown callers: per IP. The Netlify edge function sends
    | X-Seo-Edge-Key = SEO_EDGE_KEY and gets one shared, much higher bucket
    | (all edge traffic comes from few Netlify IPs). Unset key → IP limit only.
    */
    'edge_key' => env('SEO_EDGE_KEY'),
    'rate_limit' => (int) env('SEO_RATE_LIMIT', 300),
    'edge_rate_limit' => (int) env('SEO_EDGE_RATE_LIMIT', 6000),

    /*
    | Private storefront routes: always noindex,nofollow, never in sitemap,
    | disallowed in robots.txt. Matches the path itself and any sub-path.
    */
    'private_prefixes' => [
        '/cart',
        '/checkout',
        '/profile',
        '/orders',
        '/login',
        '/wishlist',
    ],

    /*
    | Static storefront pages, keyed by stable admin key → path + defaults.
    */
    'pages' => [
        'home' => [
            'path' => '/',
            'name' => 'Home',
            'title' => 'OPEL Tools — Power Tools & Hand Tools Online',
            'description' => 'Shop power tools, hand tools and workshop equipment online at OPEL Tools. Genuine products, fast delivery across India.',
        ],
        'products' => [
            'path' => '/products',
            'name' => 'All products',
            'title' => 'Shop All Tools',
            'description' => 'Browse the full OPEL Tools catalogue — power tools, hand tools, accessories and workshop equipment.',
        ],
        'about' => [
            'path' => '/about',
            'name' => 'About us',
            'title' => 'About Us',
            'description' => 'Learn about OPEL Tools — who we are and how we serve professionals and DIY customers across India.',
        ],
        'contact' => [
            'path' => '/contact',
            'name' => 'Contact',
            'title' => 'Contact Us',
            'description' => 'Get in touch with OPEL Tools for orders, product questions and support.',
        ],
        'shipping' => [
            'path' => '/shipping',
            'name' => 'Shipping policy',
            'title' => 'Shipping Policy',
            'description' => 'Delivery timelines, charges and coverage for OPEL Tools orders.',
        ],
        'returns' => [
            'path' => '/returns',
            'name' => 'Returns & refunds',
            'title' => 'Returns & Refunds',
            'description' => 'How to return an OPEL Tools order and how refunds are processed.',
        ],
        'privacy' => [
            'path' => '/privacy',
            'name' => 'Privacy policy',
            'title' => 'Privacy Policy',
            'description' => 'How OPEL Tools collects, uses and protects your personal data.',
        ],
        'terms' => [
            'path' => '/terms',
            'name' => 'Terms & conditions',
            'title' => 'Terms & Conditions',
            'description' => 'Terms and conditions for using the OPEL Tools store.',
        ],
        'warranty' => [
            'path' => '/warranty',
            'name' => 'Warranty',
            'title' => 'Warranty',
            'description' => 'Warranty coverage and claims for products bought from OPEL Tools.',
        ],
        'careers' => [
            'path' => '/careers',
            'name' => 'Careers',
            'title' => 'Careers',
            'description' => 'Work with OPEL Tools — open roles and how to apply.',
        ],
    ],
];
