<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Filament\Statistics\IncidentStatsFooterData;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-021 regression: MySQL returns DECIMAL aggregates (SUM/AVG over decimal
 * columns) as STRINGS — mysqlnd has no native decimal type. Under
 * declare(strict_types=1) those strings fatal inside round()/abs()/
 * number_format() (TypeError: Argument #1 must be of type int|float).
 *
 * This test feeds the prod-proven string values ('16072236.00', '123.45',
 * '-6.75' — from the 2026-09-29 prod crash log) through a stubbed Builder so
 * the string path is exercised deterministically regardless of test DB driver
 * (SQLite aggregates return floats, which is why the suite never caught it).
 */
class IncidentStatsFooterStringAggregateTest extends TestCase
{
    #[Test]
    public function build_tolerates_mysql_string_aggregates(): void
    {
        $query = $this->stubBuilder();

        $stats = app(IncidentStatsFooterData::class)->build($query);

        $this->assertSame(123.45, $stats['avgMttrMins']);
        $this->assertSame(6.75, $stats['avgMttrDays']);
        $this->assertIsFloat($stats['totalPotentialFundLoss']);
        $this->assertIsFloat($stats['totalFundLoss']);
        $this->assertIsFloat($stats['totalRecoveredFund']);
        $this->assertSame(16072236.0, $stats['totalFundLoss']);
    }

    /**
     * Builder stub returning exactly what MySQL does on prod: decimal
     * aggregates as strings, integer aggregates as ints. Eloquent Builder
     * forwards aggregates to the query builder via __call, so the stub hooks
     * there; where()/whereIn()/clone() are real methods.
     */
    private function stubBuilder(): Builder
    {
        $averages = ['123.45', '-6.75'];

        $query = $this->createMock(Builder::class);
        $query->method('clone')->willReturnSelf();
        $query->method('where')->willReturnSelf();
        $query->method('__call')->willReturnCallback(function (string $method) use (&$averages, &$query): mixed {
            return match ($method) {
                'whereIn', 'withoutOutliers' => $query,
                'count' => 2,
                'min' => '2026-01-01',
                'max' => '2026-09-01',
                'avg' => array_shift($averages),
                'sum' => '16072236.00',
                default => throw new \LogicException("unexpected aggregate {$method}"),
            };
        });

        return $query;
    }
}
