<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /api/v1/action-improvements — the DingTalk agent's cross-incident view
 * of action items ("what's overdue this week / for X"). Overdue = the ONE
 * definition GovernanceKpiService::overdueActionItemCount() uses
 * (openStatuses + due_date < today) — never re-derived by hand (BUG-005 rule).
 */
class ActionImprovementSearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // PIC-email upserts notify the PIC.
    }

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

    private function makeAction(array $overrides = []): ActionImprovement
    {
        $incident = Incident::factory()->create();

        return ActionImprovement::create(array_merge([
            'incident_id' => $incident->id,
            'title' => 'Fix the pool',
            'detail' => 'Bump the connection pool',
            'due_date' => now()->addDays(3)->toDateString(),
            'pic_email' => ['kevin@dana.id'],
            'status' => 'pending',
        ], $overrides));
    }

    public function test_unauthenticated_request_gets_401(): void
    {
        $this->getJson('/api/v1/action-improvements')->assertStatus(401);
    }

    public function test_token_without_action_improvements_endpoint_gets_403(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['incidents']))
            ->getJson('/api/v1/action-improvements')
            ->assertStatus(403)
            ->assertJsonPath('message', 'This token does not have permission to access this endpoint.');
    }

    public function test_lists_open_work_with_incident_no_and_total(): void
    {
        $open = $this->makeAction(['status' => 'on_progress']);
        $this->makeAction(); // pending — open work too
        $this->makeAction(['status' => 'done']);
        $this->makeAction(['status' => 'tbd']);

        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['action-improvements']))
            ->getJson('/api/v1/action-improvements')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 2);

        $data = $res->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('on_progress', $data[0]['status']);
        $this->assertSame($open->incident->no, $data[0]['incident_no'], 'rows must carry incident_no so the agent can link back');
    }

    public function test_status_filter_accepts_only_real_statuses(): void
    {
        $this->makeAction(['status' => 'pending']);
        $this->makeAction(['status' => 'done']);

        $auth = ['Authorization', 'Bearer '.$this->tokenWith(['action-improvements'])];

        $this->withHeader(...$auth)
            ->getJson('/api/v1/action-improvements?status=done')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'done');

        // A hand-typed status that is not in the enum must be rejected, not silently empty.
        $this->withHeader(...$auth)
            ->getJson('/api/v1/action-improvements?status=Open')
            ->assertStatus(422);
    }

    public function test_overdue_filter_matches_the_governance_definition(): void
    {
        $this->makeAction(['due_date' => now()->subDays(2)->toDateString(), 'status' => 'pending']);
        $this->makeAction(['due_date' => now()->subDays(2)->toDateString(), 'status' => 'done']);
        $this->makeAction(['due_date' => now()->subDays(2)->toDateString(), 'status' => 'tbd']);
        $this->makeAction(['due_date' => now()->subDays(2)->toDateString(), 'status' => 'on_progress']);
        $this->makeAction(['due_date' => now()->toDateString()]); // due today = not overdue
        $this->makeAction(['due_date' => null]); // TBD date = never chased

        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['action-improvements']))
            ->getJson('/api/v1/action-improvements?overdue=1')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 2);

        foreach ($res->json('data') as $row) {
            $this->assertContains($row['status'], ['pending', 'on_progress'], 'overdue is open work only');
        }
    }

    public function test_pic_filter_matches_a_pic_email(): void
    {
        $this->makeAction(['pic_email' => ['kevin@dana.id', 'tomy@dana.id']]);
        $this->makeAction(['pic_email' => ['sara@dana.id']]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['action-improvements']))
            ->getJson('/api/v1/action-improvements?pic=kevin@dana.id')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_incident_no_filter_scopes_to_one_incident(): void
    {
        $a = $this->makeAction();
        $this->makeAction();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['action-improvements']))
            ->getJson('/api/v1/action-improvements?incident_no='.$a->incident->no)
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_limit_one_returns_total_only_pattern(): void
    {
        $this->makeAction();
        $this->makeAction();
        $this->makeAction();

        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['action-improvements']))
            ->getJson('/api/v1/action-improvements?limit=1')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 3);

        $this->assertCount(1, $res->json('data'));
    }

    public function test_rows_do_not_leak_pic_emails_without_pii_ability(): void
    {
        $this->makeAction(['pic_email' => ['kevin@dana.id']]);

        // A token WITHOUT the read:pii ability: pic_email must be omitted
        // (the resource gates it; the filter itself still runs server-side).
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');
        $token = $user->createToken('test', ['action-improvements']);
        PersonalAccessToken::find($token->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode(['action-improvements'])])
            ->save();

        $res = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/action-improvements')
            ->assertStatus(200);

        $this->assertArrayNotHasKey('pic_email', $res->json('data.0'));
    }
}
