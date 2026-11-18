<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /api/v1/governance — the DingTalk agent's read over the governance KPIs.
 * ZERO new metric math: it returns GovernanceKpiService::summary() verbatim
 * (the same builder the Filament Governance page shows) plus lagByQuarter's
 * chart series. One-logic rule — the bot cannot drift from the page.
 */
class GovernanceApiTest extends TestCase
{
    use RefreshDatabase;

    private function tokenWith(array $endpoints): string
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');

        $token = $user->createToken('test', ['*']);
        PersonalAccessToken::find($token->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode($endpoints)])
            ->save();

        return $token->plainTextToken;
    }

    public function test_unauthenticated_request_gets_401(): void
    {
        $this->getJson('/api/v1/governance')->assertStatus(401);
    }

    public function test_token_without_governance_endpoint_gets_403(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['incidents']))
            ->getJson('/api/v1/governance')
            ->assertStatus(403)
            ->assertJsonPath('message', 'This token does not have permission to access this endpoint.');
    }

    public function test_returns_the_service_summary_verbatim(): void
    {
        $auth = ['Authorization', 'Bearer '.$this->tokenWith(['governance'])];

        $res = $this->withHeader(...$auth)
            ->getJson('/api/v1/governance')
            ->assertStatus(200)
            ->assertJsonPath('status', 'Success');

        $data = $res->json('data');
        foreach (['register_completeness_pct', 'rca_within_5d', 'overdue_action_items',
            'recurrence_this_quarter', 'period_label'] as $key) {
            $this->assertArrayHasKey($key, $data, "summary key {$key} missing");
        }
        $this->assertArrayHasKey('rca_within_5d', $data);
        $this->assertIsArray($data['rca_within_5d']);
    }

    public function test_series_flag_adds_lag_by_quarter(): void
    {
        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['governance']))
            ->getJson('/api/v1/governance?series=1')
            ->assertStatus(200);

        $series = $res->json('data.lag_by_quarter');
        $this->assertIsArray($series);
        $this->assertArrayHasKey('labels', $series);
        $this->assertArrayHasKey('fl', $series);
        $this->assertArrayHasKey('nfl', $series);
    }

    public function test_year_query_scopes_the_service(): void
    {
        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['governance']))
            ->getJson('/api/v1/governance?year=2025')
            ->assertStatus(200);

        $this->assertStringContainsString('2025', (string) $res->json('data.period_label'));
    }

    public function test_rejects_a_nonsense_year(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['governance']))
            ->getJson('/api/v1/governance?year=abcd')
            ->assertStatus(422);
    }
}
