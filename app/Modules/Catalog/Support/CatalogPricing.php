<?php

namespace App\Modules\Catalog\Support;

/**
 * The one effective-price function (storefront, cart, checkout).
 *
 *   effective = admin price override ?? pricingLogic(raw B2B price)
 *
 * pricingLogic() is the B2C markup on the raw B2B value (MRP + 10%).
 * An admin override is the final price and is not marked up again.
 * SQL paths (price filter / sort / facets in CatalogSearch) mirror this
 * with ProductVariant::effectivePriceSql() and must stay in sync.
 */
final class CatalogPricing
{
    public const MARKUP = 1.10;

    public static function effective(?string $b2bPrice, ?string $override): ?string
    {
        if ($override !== null) {
            return $override;
        }

        return $b2bPrice === null ? null : self::pricingLogic($b2bPrice);
    }

    public static function pricingLogic(string $b2bPrice): string
    {
        $cents = (int) round((float) $b2bPrice * 100);

        return number_format((int) round($cents * self::MARKUP) / 100, 2, '.', '');
    }

    /** SQL twin of pricingLogic() for a raw B2B price expression. */
    public static function pricingLogicSql(string $expression): string
    {
        return "round({$expression} * 1.10, 2)";
    }
}
