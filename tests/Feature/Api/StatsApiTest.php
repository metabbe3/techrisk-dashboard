<?php

namespace Tests\Feature\Api;

use App\Enums\Severity;
use App\Models\Incident;
use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /api/v1/stats — the DingTalk agent's metric surface.
 *
 * Owner one-logic rule (2026-11-16): the endpoint itself computes NOTHING.
 * summary delegates verbatim to IncidentStatsFooterData::build() over a
 * footer-parity scope; by_dimension delegates verbatim to
 * AnalyticsQueryService::buildSingleDataset(). These tests pin that
 * delegation through the response keys and semantics (G/Issue membership,
 * outlier exclusion, sign-split MTTR).
 */
class StatsApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');
        $this->token = $user->createToken('test-token', ['*'])->plainTextToken;
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    /**
     * Five rows covering every membership rule the summary must inherit:
     * the observer writes mttr from stop_bleeding_at (sync queue in tests) —
     * seeded the production way, never assigned directly.
     */
    private function seedSummaryRows(): void
    {
        $y = now()->year;

        Incident::factory()->create([ // eligible minutes row (minutes scale — Non fundLoss)
            'severity' => Severity::P1->value,
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => "{$y}-03-01 10:00:00",
            'stop_bleeding_at' => "{$y}-03-01 10:30:00", // mttr 30
        ]);
        Incident::factory()->create([ // eligible days row (Confirmed loss = days scale)
            'severity' => Severity::P2->value,
            'classification' => 'Incident',
            'incident_status' => 'Completed',
            'root_cause' => 'Test RCA',
            'fund_status' => 'Confirmed loss',
            'incident_date' => "{$y}-01-01 10:00:00",
            'stop_bleeding_at' => "{$y}-01-04 12:00:00", // mttr -3 (days)
            'fund_loss' => 5000000,
        ]);
        Incident::factory()->create([ // G counts in totalCases, never in MTTR
            'severity' => 'G',
            'classification' => 'Incident',
            'incident_date' => "{$y}-02-01 09:00:00",
        ]);
        Incident::factory()->create([ // Issue — excluded from the summary entirely
            'classification' => 'Issue',
            'incident_date' => "{$y}-02-02 09:00:00",
        ]);
        $outlier = Incident::factory()->create([ // Outlier leaves the averages, stays in counts
            'severity' => Severity::P3->value,
            'classification' => 'Incident',
            'incident_date' => "{$y}-02-03 09:00:00",
            'stop_bleeding_at' => "{$y}-02-03 09:10:00", // mttr 10
        ]);
        $outlier->labels()->attach(Label::firstOrCreate(['name' => Label::OUTLIER]));
    }

    public function test_unauthenticated_request_gets_401(): void
    {
        $this->getJson('/api/v1/stats')->assertStatus(401);
    }

    public function test_token_without_stats_endpoint_gets_403(): void
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');

        $restricted = $user->createToken('restricted', ['*']);
        PersonalAccessToken::find($restricted->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode(['incidents'])])
            ->save();

        $this->withHeader('Authorization', 'Bearer '.$restricted->plainTextToken)
            ->getJson('/api/v1/stats')
            ->assertStatus(403)
            ->assertJsonPath('message', 'This token does not have permission to access this endpoint.');
    }

    public function test_token_with_only_stats_endpoint_gets_200(): void
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');

        $allowed = $user->createToken('stats-only', ['*']);
        PersonalAccessToken::find($allowed->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode(['stats'])])
            ->save();

        $this->withHeader('Authorization', 'Bearer '.$allowed->plainTextToken)
            ->getJson('/api/v1/stats')
            ->assertStatus(200)
            ->assertJsonPath('message', 'Stats retrieved successfully.');
    }

    public function test_summary_delegates_to_the_footer_builder(): void
    {
        $this->seedSummaryRows();

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/stats')
            ->assertStatus(200)
            ->assertJsonPath('data.totalCases', 4) // P1 + P2 + G + Outlier — Issue excluded, G counts
            ->assertJsonPath('data.avgMttrMins', 30)
            ->assertJsonPath('data.avgMttrDays', 3) // P2's -3 days bucket
            ->assertJsonPath('data.totalFundLoss', 5000000)
            ->assertJsonStructure(['data' => ['totalCases', 'avgMttrMins', 'avgMttrDays', 'avgMtbf', 'totalPotentialFundLoss', 'totalFundLoss', 'totalRecoveredFund']]);
    }

    public function test_summary_applies_date_and_enum_filters(): void
    {
        $this->seedSummaryRows();

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/stats?'.http_build_query([
                'severities' => ['P2'],
                'fund_statuses' => ['Confirmed loss'],
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.totalCases', 1)
            ->assertJsonPath('data.totalFundLoss', 5000000)
            ->assertJsonPath('data.avgMttrDays', 3)
            ->assertJsonPath('data.avgMttrMins', 0);
    }

    public function test_by_dimension_count_by_severity(): void
    {
        $y = now()->year;
        foreach (['P1', 'P1', 'P2'] as $severity) {
            Incident::factory()->create([
                'severity' => $severity,
                'classification' => 'Incident',
                'incident_date' => "{$y}-03-01 10:00:00",
            ]);
        }
        Incident::factory()->create([ // G must never reach a severity breakdown
            'severity' => 'G',
            'classification' => 'Incident',
            'incident_date' => "{$y}-03-02 10:00:00",
        ]);

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/stats?'.http_build_query(['mode' => 'by_dimension', 'metric' => 'count', 'dimension' => 'severity']))
            ->assertStatus(200)
            ->assertJsonPath('data.labels.0', 'P1')
            ->assertJsonPath('data.values.0', 2)
            ->assertJsonPath('data.values.1', 1);
    }

    public function test_by_dimension_sum_fund_loss_by_responsible_team(): void
    {
        $y = now()->year;
        Incident::factory()->create([
            'severity' => Severity::P1->value,
            'classification' => 'Incident',
            'responsible_team' => ['Network'],
            'incident_date' => "{$y}-03-01 10:00:00",
            'fund_loss' => 1200000,
        ]);
        Incident::factory()->create([
            'severity' => Severity::P2->value,
            'classification' => 'Incident',
            'responsible_team' => ['Security'],
            'incident_date' => "{$y}-03-02 10:00:00",
            'fund_loss' => 300000,
        ]);

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/stats?'.http_build_query(['mode' => 'by_dimension', 'metric' => 'sum_fund_loss', 'dimension' => 'responsible_team']))
            ->assertStatus(200)
            ->assertJsonPath('data.labels.0', 'Network')
            ->assertJsonPath('data.values.0', 1200000)
            ->assertJsonPath('data.values.1', 300000);
    }

    public function test_by_dimension_requires_metric_and_dimension(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/stats?mode=by_dimension')
            ->assertStatus(422);
    }

    public function test_unknown_metric_returns_422(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/stats?'.http_build_query(['mode' => 'by_dimension', 'metric' => 'avg_uptime', 'dimension' => 'severity']))
            ->assertStatus(422);
    }

    public function test_unknown_dimension_returns_422(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/stats?'.http_build_query(['mode' => 'by_dimension', 'metric' => 'count', 'dimension' => 'galaxy']))
            ->assertStatus(422);
    }

    public function test_unknown_mode_returns_422(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/stats?mode=everything')
            ->assertStatus(422);
    }
}
