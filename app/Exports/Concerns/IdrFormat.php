<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Single home for Rupiah rendering in exports (BUG-005/006/007 lesson:
 * one rule, one definition — never hand-typed per sheet).
 *
 * Native Excel number format keeps cells real numbers (summable), unlike
 * pre-formatted 'Rp x.xxx' strings.
 */
class IdrFormat
{
    public const FORMAT = '"Rp "#,##0';

    private const FUND_COLUMNS = ['potential_fund_loss', 'fund_loss', 'recovered_fund'];

    public static function isFundColumn(string $column): bool
    {
        return in_array($column, self::FUND_COLUMNS, true);
    }

    /**
     * Excel column letters of the fund columns within a dynamic column list
     * (A-based, order preserved) — for sheets whose columns the user picks.
     *
     * @param  string[]  $columnNames
     * @return string[]
     */
    public static function letters(array $columnNames): array
    {
        $letters = [];
        foreach (array_values($columnNames) as $i => $name) {
            if (self::isFundColumn($name)) {
                $letters[] = Coordinate::stringFromColumnIndex($i + 1);
            }
        }

        return $letters;
    }
}
