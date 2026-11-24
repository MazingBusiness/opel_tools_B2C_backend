<?php

use App\Modules\Address\Providers\AddressServiceProvider;
use App\Modules\Admin\Providers\AdminServiceProvider;
use App\Modules\Auth\Providers\AuthServiceProvider;
use App\Modules\Cart\Providers\CartServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Fulfilment\Providers\FulfilmentServiceProvider;
use App\Modules\Order\Providers\OrderServiceProvider;
use App\Modules\Seo\Providers\SeoServiceProvider;
use App\Modules\Wishlist\Providers\WishlistServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    CatalogServiceProvider::class,
    WishlistServiceProvider::class,
    CartServiceProvider::class,
    AddressServiceProvider::class,
    OrderServiceProvider::class,
    AdminServiceProvider::class,
    SeoServiceProvider::class,
    FulfilmentServiceProvider::class,
];
