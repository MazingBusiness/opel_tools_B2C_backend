<?php

namespace App\Modules\Catalog\Support;

/**
 * The one effective-price function (storefront, cart, checkout).
 *
 *   effective = admin price override ?? pricingLogic(raw B2B price)
 *
 * pricingLogic() is the seam for the future "B2B price x logic" rule; today it
 * is the identity. SQL paths (price filter / sort / facets in CatalogSearch)
 * mirror this with ProductVariant::effectivePriceSql() and must be updated
 * together if the logic stops being the identity.
 */
final class CatalogPricing
{
    public static function effective(?string $b2bPrice, ?string $override): ?string
    {
        if ($override !== null) {
            return $override;
        }

        return $b2bPrice === null ? null : self::pricingLogic($b2bPrice);
    }

    public static function pricingLogic(string $b2bPrice): string
    {
        return $b2bPrice;
    }
}
