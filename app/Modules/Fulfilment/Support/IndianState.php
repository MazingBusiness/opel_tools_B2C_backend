<?php

namespace App\Modules\Fulfilment\Support;

/**
 * Place-of-supply comparison for GST. Addresses are free text, so old names,
 * codes and long forms are folded to one canonical state before comparing.
 */
final class IndianState
{
    /** @var array<string, string> lowercased alias => canonical */
    private const ALIASES = [
        'delhi' => 'delhi', 'new delhi' => 'delhi', 'nct of delhi' => 'delhi', 'delhi nct' => 'delhi',
        'national capital territory of delhi' => 'delhi', 'nct delhi' => 'delhi', 'dl' => 'delhi',
        'west bengal' => 'west bengal', 'westbengal' => 'west bengal', 'wb' => 'west bengal',
        'maharashtra' => 'maharashtra', 'maharastra' => 'maharashtra', 'mh' => 'maharashtra',
        'odisha' => 'odisha', 'orissa' => 'odisha',
        'puducherry' => 'puducherry', 'pondicherry' => 'puducherry',
        'uttarakhand' => 'uttarakhand', 'uttaranchal' => 'uttarakhand',
        'jammu and kashmir' => 'jammu and kashmir', 'j&k' => 'jammu and kashmir', 'j and k' => 'jammu and kashmir',
        'andaman and nicobar islands' => 'andaman and nicobar islands', 'andaman and nicobar' => 'andaman and nicobar islands',
        'dadra and nagar haveli and daman and diu' => 'dadra and nagar haveli and daman and diu',
        'dadra and nagar haveli' => 'dadra and nagar haveli and daman and diu', 'daman and diu' => 'dadra and nagar haveli and daman and diu',
        'tamil nadu' => 'tamil nadu', 'tamilnadu' => 'tamil nadu',
        'telangana' => 'telangana', 'telengana' => 'telangana',
        'chhattisgarh' => 'chhattisgarh', 'chattisgarh' => 'chhattisgarh',
    ];

    public static function canonical(string $state): string
    {
        $s = strtolower(trim($state));
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace('/[^a-z ]+/', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;
        $s = preg_replace('/^(the )?(state of |ut of |union territory of )/', '', $s) ?? $s;

        return self::ALIASES[$s] ?? self::ALIASES[str_replace(' ', '', $s)] ?? $s;
    }

    public static function same(string $a, string $b): bool
    {
        return self::canonical($a) !== '' && self::canonical($a) === self::canonical($b);
    }
}
