<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Filament\Statistics\IncidentStatsFooterData;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tab parity (BUG-022): the Incidents table's Summary footer must read the
 * SAME rows the table under it shows — the ACTIVE TAB first of all. The
 * pre-fix footer ran a request-derived query only, so the Fund Loss / Non
 * Fund Loss tabs showed All Cases MTTR/MTBF/totals.
 *
 * The stats builder is fed `$livewire->getFilteredTableQuery()` — exactly
 * what the resource's contentFooter passes since the fix.
 */
class IncidentStatsFooterTabParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo('view incidents');
        $this->actingAs($user);
    }

    public function test_footer_matches_the_active_fund_tab_not_all_cases(): void
    {
        $year = now()->year;

        // Fund Loss: one minutes row, one days row (sign rule) 20 days apart.
        // Non Fund Loss: a different minutes row. All Cases sees all three.
        Incident::factory()->createQuietly([
            'classification' => 'Incident',
            'severity' => 'P1',
            'fund_status' => 'Confirmed loss',
            'incident_date' => "{$year}-01-10 09:00:00",
            'mttr' => 120,
            'fund_loss' => 500,
        ]);
        Incident::factory()->createQuietly([
            'classification' => 'Incident',
            'severity' => 'P2',
            'fund_status' => 'Confirmed loss',
            'incident_date' => "{$year}-01-30 09:00:00",
            'mttr' => -3,
        ]);
        Incident::factory()->createQuietly([
            'classification' => 'Incident',
            'severity' => 'P1',
            'fund_status' => 'Non fundLoss',
            'incident_date' => "{$year}-02-10 09:00:00",
            'mttr' => 90,
            'fund_loss' => 111,
        ]);

        // Every stat asserted per tab, one by one.
        $all = $this->footerStatsFor('All Cases');
        $this->assertSame(3, $all['totalCases']);
        $this->assertSame(105.0, $all['avgMttrMins']);
        $this->assertSame(3.0, $all['avgMttrDays']);
        $this->assertSame(15.5, $all['avgMtbf']); // Jan 10 → Feb 10 = 31 / (3-1)
        $this->assertSame(611.0, $all['totalFundLoss']);

        $fund = $this->footerStatsFor('Fund Loss');
        $this->assertSame(2, $fund['totalCases']);
        $this->assertSame(120.0, $fund['avgMttrMins'], 'the Non fundLoss minutes row is not on this tab');
        $this->assertSame(3.0, $fund['avgMttrDays']);
        $this->assertSame(20.0, $fund['avgMtbf']); // Jan 10 → Jan 30 = 20 / (2-1)
        $this->assertSame(500.0, $fund['totalFundLoss']);

        $nonFund = $this->footerStatsFor('Non Fund Loss');
        $this->assertSame(1, $nonFund['totalCases']);
        $this->assertSame(90.0, $nonFund['avgMttrMins']);
        $this->assertEquals(0, $nonFund['avgMttrDays'], 'no days-bucket row on this tab');
        $this->assertEquals(0, $nonFund['avgMtbf'], 'single row has no gap sequence');
        $this->assertSame(111.0, $nonFund['totalFundLoss']);

        // The whole point: the tab footer must NOT be the All Cases footer.
        $this->assertNotSame($all['totalCases'], $fund['totalCases']);
        $this->assertNotSame($all['avgMttrMins'], $fund['avgMttrMins']);
        $this->assertNotSame($all['avgMtbf'], $nonFund['avgMtbf']);
    }

    /**
     * The exact input the fixed contentFooter passes: the ListIncidents
     * component's filtered query for the given tab (tab + filters + search).
     */
    private function footerStatsFor(string $tab): array
    {
        $component = Livewire::test(ListIncidents::class)
            ->set('activeTab', $tab);

        return app(IncidentStatsFooterData::class)
            ->build($component->instance()->getFilteredTableQuery()->clone());
    }
}
