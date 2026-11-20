<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\Incident;
use App\Models\User;
use App\Services\Ai\AiTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Review I2 (PROJ-022): the AI trend endpoint's fund-loss figure must keep
 * sub-incidents (money stays with subs) even though the count base excludes
 * them — AI must agree with the Fund Loss tile, not the counts.
 */
class AnalyzeTrendsFundLossTest extends TestCase
{
    use RefreshDatabase;

    public function test_trend_fund_loss_keeps_sub_incidents(): void
    {
        $captured = null;
        $ai = $this->createMock(AiTextService::class);
        $ai->method('isAvailable')->willReturn(true);
        $ai->method('analyzeTrends')->willReturnCallback(
            function (array $monthlyData, array $topLabels, array $topPics, array $stats) use (&$captured): array {
                $captured = $stats;

                return ['trends' => [], 'recurring_issues' => [], 'anomalies' => [], 'recommendations' => []];
            }
        );
        $this->app->instance(AiTextService::class, $ai);

        $super = Incident::factory()->create([
            'classification' => 'Incident',
            'fund_status' => 'Confirmed loss',
            'incident_status' => 'Completed',
            'fund_loss' => 5000000,
            'incident_date' => now()->startOfDay(),
            'root_cause' => 'x',
            'stop_bleeding_at' => now(),
        ]);

        Incident::factory()->subOf($super)->create([
            'classification' => 'Incident',
            'fund_status' => 'Confirmed loss',
            'incident_status' => 'Completed',
            'fund_loss' => 2000000,
            'incident_date' => now()->startOfDay(),
            'root_cause' => 'x',
            'stop_bleeding_at' => now(),
        ]);

        Permission::firstOrCreate(['name' => 'access dashboard']);
        $user = User::factory()->create();
        $user->givePermissionTo('access dashboard');

        $this->actingAs($user)
            ->postJson('/admin/ai/analyze-trends', ['force_refresh' => true])
            ->assertOk();

        // number_format(..., 0, ',', '.') — the sub's 2M must ride along.
        $this->assertSame('7.000.000', $captured['fund_loss']);
        Cache::forget('chat_quick_stats_v2');
    }
}
