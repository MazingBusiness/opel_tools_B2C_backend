<?php

namespace App\Modules\Fulfilment\Support;

final class Branches
{
    /** @return array<string, array{name: string, short: string, state: string, b2b_warehouse_id: int, stock_key: string}> */
    public static function all(): array
    {
        return config('branches');
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function short(string $code): string
    {
        return self::all()[$code]['short'];
    }
}
