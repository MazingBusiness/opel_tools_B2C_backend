<?php

use App\Modules\Auth\Providers\AuthServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Wishlist\Providers\WishlistServiceProvider;
use App\Modules\Cart\Providers\CartServiceProvider;
use App\Modules\Address\Providers\AddressServiceProvider;
use App\Modules\Order\Providers\OrderServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    CatalogServiceProvider::class,
    WishlistServiceProvider::class,
    CartServiceProvider::class,
    AddressServiceProvider::class,
    OrderServiceProvider::class,
];
