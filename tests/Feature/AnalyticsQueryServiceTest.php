<?php

namespace Tests\Feature;

use App\Services\Analytics\AnalyticsQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedIncidents(): void
    {
        // IncidentObserver::created runs CalculateIncidentMetrics synchronously
        // (QUEUE_CONNECTION=sync in tests), so mttr must come from
        // stop_bleeding_at — the same way production writes it — rather than
        // being seeded directly and then overwritten to null.
        \App\Models\Incident::factory()->createMany([
            [
                'severity' => 'P1',
                'classification' => 'Incident',
                'fund_status' => 'Non fundLoss',
                'incident_date' => '2026-03-01 10:00:00',
                'stop_bleeding_at' => '2026-03-01 11:00:00', // mttr 60 min
                'business_category' => ['Payments'],
            ],
            [
                'severity' => 'P1',
                'classification' => 'Incident',
                'fund_status' => 'Non fundLoss',
                'incident_date' => '2026-03-11 10:00:00',
                'stop_bleeding_at' => '2026-03-11 12:00:00', // mttr 120 min
                'business_category' => ['Payments'],
            ],
            // Non-incident severities must NOT pollute averages
            [
                'severity' => 'Non Incident',
                'classification' => 'Incident',
                'fund_status' => 'Non fundLoss',
                'incident_date' => '2026-03-21 10:00:00',
                'stop_bleeding_at' => '2026-03-21 22:00:00', // mttr 720 min
                'business_category' => ['Payments'],
            ],
            [
                'severity' => 'G',
                'classification' => 'Incident',
                'fund_status' => 'Non fundLoss',
                'incident_date' => '2026-03-26 10:00:00',
                'stop_bleeding_at' => '2026-03-26 22:00:00', // mttr 720 min
                'business_category' => ['Payments'],
            ],
        ]);
    }

    // ponytail: severity dimension instead of monthly — DATE_FORMAT in
    // queryTimeDimension is MySQL-only, sqlite test DB can't run it. The
    // severity filter guard is exercised either way: without it the
    // 'Non Incident' group reappears with mttr 9999.
    public function test_avg_mttr_excludes_non_incident_severities(): void
    {
        $this->seedIncidents();

        $result = app(AnalyticsQueryService::class)
            ->buildSingleDataset('avg_mttr', 'severity', []);

        $this->assertNotContains('Non Incident', $result['labels']);
        $this->assertNotContains('G', $result['labels']);
        $p1 = array_search('P1', $result['labels']);
        $this->assertNotFalse($p1);
        $this->assertSame(90.0, $result['values'][$p1]);
    }

    public function test_avg_mttr_days_excludes_non_incident_severities(): void
    {
        $this->seedIncidents();

        $result = app(AnalyticsQueryService::class)
            ->buildSingleDataset('avg_mttr_days', 'severity', []);

        $this->assertNotContains('Non Incident', $result['labels']);
        $p1 = array_search('P1', $result['labels']);
        $this->assertNotFalse($p1);
        $this->assertSame(0.0, $result['values'][$p1]);
    }

    public function test_avg_mtbf_json_dimension_uses_eligible_dates_only(): void
    {
        $this->seedIncidents();

        // Before fix: severity was never selected -> mtbf_dates always empty -> 0.
        // After fix: eligible span = Mar 1..11, 1 gap = 10 days.
        $result = app(AnalyticsQueryService::class)
            ->buildSingleDataset('avg_mtbf', 'business_category', []);

        $payments = array_search('Payments', $result['labels']);
        $this->assertNotFalse($payments);
        $this->assertSame(10.0, $result['values'][$payments]);
    }
}
