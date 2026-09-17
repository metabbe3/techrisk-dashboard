<?php

namespace Tests\Feature;

use App\Enums\Severity;
use App\Filament\Widgets\DashboardStatsOverview;
use App\Models\Incident;
use App\Services\IncidentStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IncidentCountSeverityRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function seedCountRows(): void
    {
        // Three rows that all pass classification + fund-status rules; only
        // the first has a severity that counts.
        Incident::factory()->create([
            'severity' => Severity::P3->value,
            'classification' => 'Incident',
            'incident_date' => '2026-03-01 10:00:00',
            'incident_status' => 'Open',
            'fund_status' => 'Non fundLoss',
        ]);
        Incident::factory()->create([
            'severity' => Severity::G->value,
            'classification' => 'Incident',
            'incident_date' => '2026-03-02 10:00:00',
            'incident_status' => 'Open',
            'fund_status' => 'Non fundLoss',
        ]);
        Incident::factory()->create([
            'severity' => Severity::NonIncident->value,
            'classification' => 'Incident',
            'incident_date' => '2026-03-03 10:00:00',
            'incident_status' => 'Open',
            'fund_status' => 'Non fundLoss',
        ]);
        // Same excluded severities on an Issue row — must not count either.
        Incident::factory()->create([
            'severity' => Severity::G->value,
            'classification' => 'Issue',
            'incident_date' => '2026-03-04 10:00:00',
            'incident_status' => 'Open',
            'fund_status' => 'Non fundLoss',
        ]);
    }

    public function test_ai_counts_excludes_glitch_and_non_incident_severity(): void
    {
        $this->seedCountRows();

        $this->assertSame(1, Incident::query()->aiCounts()->count());
    }

    public function test_count_eligible_excludes_glitch_and_non_incident_severity(): void
    {
        $this->seedCountRows();

        $this->assertSame(1, Incident::query()->countEligible()->count());
    }

    public function test_dashboard_cards_exclude_glitch_and_non_incident_severity(): void
    {
        $this->seedCountRows();

        $widget = app(DashboardStatsOverview::class);
        $stats = \Closure::bind(fn () => $this->calculateStats(), $widget, DashboardStatsOverview::class)();
        $values = collect($stats)->map(fn ($stat) => $stat->getValue())->values();

        // Total Incidents (aiCounts) and Total Issues card (countEligible).
        $this->assertSame('1', (string) $values[0]);
        $this->assertSame('1', (string) $values[1]);
    }

    public function test_stats_service_base_stats_exclude_glitch_and_non_incident_severity(): void
    {
        $this->seedCountRows();

        $stats = app(IncidentStatsService::class)->getBaseStats(
            now()->startOfYear(),
            now()->endOfYear(),
        );

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['open']);
        $this->assertArrayNotHasKey(Severity::G->value, $stats['by_severity']);
        $this->assertArrayNotHasKey(Severity::NonIncident->value, $stats['by_severity']);
    }
}
