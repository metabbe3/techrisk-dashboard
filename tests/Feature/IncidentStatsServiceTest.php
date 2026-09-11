<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Services\IncidentStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_stats_fund_loss_excludes_issues(): void
    {
        Incident::factory()->create([
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'fund_loss' => 1000,
            'incident_date' => now()->startOfYear()->addDays(10),
        ]);

        // An Issue carrying fund_loss must not leak into the incident sum —
        // total/open/severity already filtered classification, fund_loss didn't.
        Incident::factory()->create([
            'classification' => 'Issue',
            'fund_status' => 'Non fundLoss',
            'fund_loss' => 999999,
            'incident_date' => now()->startOfYear()->addDays(11),
        ]);

        $stats = app(IncidentStatsService::class)->getBaseStats(
            now()->startOfYear(),
            now()->endOfYear(),
        );

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1000.0, (float) $stats['fund_loss']);
    }
}
