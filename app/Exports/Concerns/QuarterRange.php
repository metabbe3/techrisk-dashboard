<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

use Illuminate\Support\Carbon;

/**
 * "YYYY-Qn" quarter values shared by the Group-By quarter dimension, the
 * export f_quarter filter and the Executive per-quarter tabs. Laravel has
 * no whereQuarter() — quarters filter by date range.
 */
class QuarterRange
{
    /** "2026-Q1" → [Carbon start, Carbon end] covering that quarter (inclusive). */
    public static function dates(string $quarter): array
    {
        [$year, $qtr] = explode('-Q', $quarter);
        $start = Carbon::create((int) $year, (int) $qtr * 3 - 2, 1)->startOfDay();

        return [$start, $start->copy()->endOfQuarter()];
    }

    /** "2026-Q1" → "Q1 2026". */
    public static function label(string $quarter): string
    {
        [$year, $qtr] = explode('-Q', $quarter);

        return "Q{$qtr} {$year}";
    }
}
