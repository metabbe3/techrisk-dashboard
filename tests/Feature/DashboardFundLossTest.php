<?php

namespace Tests\Feature;

use App\Filament\Widgets\DashboardStatsOverview;
use App\Filament\Widgets\PotentialFundLoss;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardFundLossTest extends TestCase
{
    use RefreshDatabase;

    public function test_potential_fund_loss_excludes_completed_cases(): void
    {
        Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'incident_date' => '2026-03-01 10:00:00',
            'incident_status' => 'Completed',
            'fund_status' => 'Non fundLoss',
            'potential_fund_loss' => 500,
        ]);
        Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'incident_date' => '2026-03-11 10:00:00',
            'incident_status' => 'Open',
            'fund_status' => 'Non fundLoss',
            'potential_fund_loss' => 300,
        ]);

        Livewire::test(PotentialFundLoss::class)
            ->assertSee('IDR 300,00')
            ->assertDontSee('IDR 500,00');
    }

    public function test_fund_loss_card_excludes_issues(): void
    {
        Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Issue',
            'incident_date' => '2026-03-01 10:00:00',
            'incident_status' => 'Completed',
            'fund_status' => 'Non fundLoss',
            'fund_loss' => 999,
        ]);
        Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'incident_date' => '2026-03-11 10:00:00',
            'incident_status' => 'Completed',
            'fund_status' => 'Non fundLoss',
            'fund_loss' => 500,
        ]);

        // Fund Loss card formats with 0 decimals ("IDR 500"), unlike the
        // Potential Fund Loss widget which uses 2.
        Livewire::test(DashboardStatsOverview::class)
            ->assertSee('IDR 500')
            ->assertDontSee('IDR 999');
    }

    public function test_editing_money_fields_bumps_dashboard_cache_version(): void
    {
        $incident = Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'incident_date' => '2026-03-01 10:00:00',
            'fund_status' => 'Non fundLoss',
        ]);

        $version = Cache::get('dashboard_cache_version', 0);

        $incident->update(['fund_loss' => 123]);

        $this->assertGreaterThan(
            $version,
            Cache::get('dashboard_cache_version', 0),
            'Editing fund_loss should recalculate metrics and bump the dashboard cache version'
        );
    }
}
