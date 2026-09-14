<?php

namespace App\Modules\Catalog\Support;

class PartNumber
{
    public static function normalize(?string $partNo): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', (string) $partNo));
    }
}
