<?php

namespace App\Modules\Admin\Support;

/** Areas of the admin a sub-admin can be given. Managing admins is super-only and not a module. */
final class AdminModules
{
    public const ORDERS = 'orders';       // orders, approval and allocation

    public const FULFILMENT = 'fulfilment'; // sub-orders, challans, B2B pushes, invoices (branch-scoped)

    public const USERS = 'users';         // shoppers

    public const CATALOG = 'catalog';

    public const HOMEPAGE = 'homepage';

    public const SEO = 'seo';

    public const ALL = [self::ORDERS, self::FULFILMENT, self::USERS, self::CATALOG, self::HOMEPAGE, self::SEO];
}
