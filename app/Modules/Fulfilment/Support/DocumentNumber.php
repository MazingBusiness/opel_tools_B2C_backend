<?php

namespace App\Modules\Fulfilment\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Gapless per-series counters (e.g. SO per branch per financial year).
 * Must run inside the caller's transaction so a rollback returns the number.
 */
final class DocumentNumber
{
    /** Indian financial year, April to March: 2026-11 → "26-27". */
    public static function financialYear(CarbonInterface $at): string
    {
        $start = $at->month >= 4 ? $at->year : $at->year - 1;

        return sprintf('%02d-%02d', $start % 100, ($start + 1) % 100);
    }

    public static function next(string $key): int
    {
        DB::table('document_counters')->insertOrIgnore([
            'key' => $key, 'value' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $value = (int) DB::table('document_counters')->where('key', $key)->lockForUpdate()->value('value') + 1;
        DB::table('document_counters')->where('key', $key)->update(['value' => $value, 'updated_at' => now()]);

        return $value;
    }

    /** Business dates (financial year) are Indian time; the app clock is UTC. */
    public static function now(): CarbonInterface
    {
        return now('Asia/Kolkata');
    }

    /** SO/KOL/000001/26-27 */
    public static function subOrder(string $branch, CarbonInterface $at): string
    {
        $at = $at->copy()->setTimezone('Asia/Kolkata');
        $short = Branches::short($branch);
        $fy = self::financialYear($at);
        $n = self::next("SO:{$short}:{$fy}");

        return sprintf('SO/%s/%06d/%s', $short, $n, $fy);
    }

    /** CH/KOL/000001/26-27 */
    public static function challan(string $branch, CarbonInterface $at): string
    {
        $at = $at->copy()->setTimezone('Asia/Kolkata');
        $short = Branches::short($branch);
        $fy = self::financialYear($at);

        return sprintf('CH/%s/%06d/%s', $short, self::next("CH:{$short}:{$fy}"), $fy);
    }

    /** KOL/B2C/0001/26-27 */
    public static function invoice(string $branch, CarbonInterface $at): string
    {
        $at = $at->copy()->setTimezone('Asia/Kolkata');
        $short = Branches::short($branch);
        $fy = self::financialYear($at);

        return sprintf('%s/B2C/%04d/%s', $short, self::next("INV:{$short}:{$fy}"), $fy);
    }
}
