<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Statistics\IncidentStatsFooterData;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Summary-filter parity (BUG-018 follow-through): the footer cards must
 * reflect the SAME date scope the table under them uses — quick_period AND
 * the custom From/Until range, stacked (intersection) exactly like the
 * table's filter chain. Previously the footer mirrored quick_period only,
 * so any custom_date_range left the summary contradicting the table.
 *
 * Every stat key is asserted one by one per scenario.
 */
class IncidentStatsFooterFilterParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_summary_respects_custom_date_range(): void
    {
        $this->seedWindow();

        request()->merge(['tableFilters' => [
            'quick_period' => ['value' => 'year'],
            'custom_date_range' => ['from' => now()->startOfYear()->toDateString(), 'until' => now()->startOfYear()->addMonths(1)->endOfMonth()->toDateString()],
        ]]);

        $stats = app(IncidentStatsFooterData::class)->build();

        // One by one — every card must scope to the Jan–Feb window.
        $this->assertSame(2, $stats['totalCases']);
        $this->assertSame(120.0, $stats['avgMttrMins']);
        $this->assertSame(3.0, $stats['avgMttrDays']);
        $this->assertSame(41.0, $stats['avgMtbf']);
        $this->assertSame(30.0, $stats['totalPotentialFundLoss']);
        $this->assertSame(300.0, $stats['totalFundLoss']);
        $this->assertSame(50.0, $stats['totalRecoveredFund']);
    }

    public function test_summary_stacks_quick_period_with_custom_range(): void
    {
        $this->seedWindow();

        // Year period ∩ Jan–Feb range: only the two in-window rows count
        // (the March row is inside the year period but outside the range).
        request()->merge(['tableFilters' => [
            'quick_period' => ['value' => 'year'],
            'custom_date_range' => ['from' => now()->startOfYear()->toDateString(), 'until' => now()->startOfYear()->addMonths(1)->endOfMonth()->toDateString()],
        ]]);
        $this->assertSame(2, app(IncidentStatsFooterData::class)->build()['totalCases']);

        // Week period ∩ whole-year range: intersection is the current week —
        // and the range must not clobber the period (still scoped to the week).
        Incident::factory()->createQuietly([
            'classification' => 'Incident',
            'severity' => 'P1',
            'incident_date' => now()->startOfWeek()->addHours(3),
            'mttr' => 120,
            'fund_loss' => 500,
        ]);
        request()->merge(['tableFilters' => [
            'quick_period' => ['value' => 'week'],
            'custom_date_range' => ['from' => now()->startOfYear()->toDateString(), 'until' => now()->endOfYear()->toDateString()],
        ]]);
        $stats = app(IncidentStatsFooterData::class)->build();
        $this->assertSame(1, $stats['totalCases']);
        $this->assertSame(120.0, $stats['avgMttrMins']);
        $this->assertSame(500.0, $stats['totalFundLoss']);
    }

    public function test_summary_respects_each_quick_period_value(): void
    {
        Incident::factory()->createQuietly([
            'classification' => 'Incident',
            'severity' => 'P1',
            // today, not startOfWeek(): on the 1st–3rd of a month the week
            // can start in the previous month and quick_period=month finds
            // nothing (flaky every month-start)
            'incident_date' => now()->startOfDay()->addHours(3),
            'fund_loss' => 100,
        ]);
        Incident::factory()->createQuietly([
            'classification' => 'Incident',
            'severity' => 'P2',
            'incident_date' => now()->subMonths(2)->startOfMonth(),
            'fund_loss' => 999,
        ]);

        $periods = [
            'week' => 1, // only the this-week row
            'month' => 1, // the other row is 2 months back
            'all' => 2,
        ];

        foreach ($periods as $value => $expected) {
            request()->merge(['tableFilters' => ['quick_period' => ['value' => $value]]]);
            $stats = app(IncidentStatsFooterData::class)->build();
            $this->assertSame($expected, $stats['totalCases'], "quick_period={$value}");
        }
    }

    /**
     * Jan 10 (mttr +120 min), Feb 20 (mttr -3 days), Mar 25 (outside the
     * Jan–Feb custom range used by the range tests).
     */
    private function seedWindow(): void
    {
        $year = now()->year;

        Incident::factory()->createQuietly([
            'classification' => 'Incident',
            'severity' => 'P1',
            'incident_date' => "{$year}-01-10 09:00:00",
            'mttr' => 120,
            'potential_fund_loss' => 10,
            'fund_loss' => 100,
            'recovered_fund' => 50,
        ]);
        Incident::factory()->createQuietly([
            'classification' => 'Incident',
            'severity' => 'P2',
            'incident_date' => "{$year}-02-20 09:00:00",
            'mttr' => -3,
            'potential_fund_loss' => 20,
            'fund_loss' => 200,
            'recovered_fund' => 0,
        ]);
        Incident::factory()->createQuietly([
            'classification' => 'Incident',
            'severity' => 'P3',
            'incident_date' => "{$year}-03-25 09:00:00",
            'mttr' => 60,
            'potential_fund_loss' => 70,
            'fund_loss' => 700,
            'recovered_fund' => 70,
        ]);
    }
}
