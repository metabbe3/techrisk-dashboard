<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ActionImprovementEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $this->user->givePermissionTo('access api');

        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    private function authenticatedHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    // --- List action improvements for an incident ---

    public function test_can_list_action_improvements_for_incident(): void
    {
        $incident = Incident::factory()->create();

        ActionImprovement::create([
            'incident_id' => $incident->id,
            'title' => 'Increase connection pool',
            'detail' => 'Configure pool to handle 2x peak traffic',
            'due_date' => '2025-02-01',
            'pic_email' => ['john@example.com'],
            'status' => 'pending',
        ]);

        ActionImprovement::create([
            'incident_id' => $incident->id,
            'title' => 'Add circuit breaker',
            'detail' => 'Implement circuit breaker pattern',
            'due_date' => '2025-02-15',
            'pic_email' => ['jane@example.com'],
            'status' => 'done',
        ]);

        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson("/api/incidents/{$incident->id}/action-improvements");

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'status' => 'Success',
                'message' => 'Action improvements retrieved successfully.',
            ]);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(2, $data);
    }

    public function test_list_action_improvements_returns_empty_for_incident_with_none(): void
    {
        $incident = Incident::factory()->create();

        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson("/api/incidents/{$incident->id}/action-improvements");

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'status' => 'Success',
                'message' => 'Action improvements retrieved successfully.',
            ]);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }

    public function test_list_action_improvements_for_nonexistent_incident_returns_404(): void
    {
        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson('/api/incidents/99999/action-improvements');

        $response->assertStatus(404);
    }

    public function test_list_action_improvements_response_structure(): void
    {
        $incident = Incident::factory()->create();

        ActionImprovement::create([
            'incident_id' => $incident->id,
            'title' => 'Fix connection timeout',
            'detail' => 'Increase timeout to 30 seconds',
            'due_date' => '2025-03-01',
            'pic_email' => ['admin@example.com'],
            'status' => 'pending',
        ]);

        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson("/api/incidents/{$incident->id}/action-improvements");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'code',
                'status',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'detail',
                        'due_date',
                        'pic_email',
                        'reminder',
                        'reminder_frequency',
                        'status',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }

    public function test_list_action_improvements_only_returns_improvements_for_given_incident(): void
    {
        $incident1 = Incident::factory()->create();
        $incident2 = Incident::factory()->create();

        ActionImprovement::create([
            'incident_id' => $incident1->id,
            'title' => 'Incident 1 Action',
            'detail' => 'Detail for incident 1',
            'due_date' => '2025-03-01',
            'pic_email' => ['user1@example.com'],
            'status' => 'pending',
        ]);

        ActionImprovement::create([
            'incident_id' => $incident2->id,
            'title' => 'Incident 2 Action',
            'detail' => 'Detail for incident 2',
            'due_date' => '2025-03-15',
            'pic_email' => ['user2@example.com'],
            'status' => 'pending',
        ]);

        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson("/api/incidents/{$incident1->id}/action-improvements");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Incident 1 Action', $data[0]['title']);
    }

    // --- Show single action improvement ---

    public function test_can_show_single_action_improvement(): void
    {
        $incident = Incident::factory()->create();

        $action = ActionImprovement::create([
            'incident_id' => $incident->id,
            'title' => 'Increase connection pool',
            'detail' => 'Configure pool to handle 2x peak traffic',
            'due_date' => '2025-02-01',
            'pic_email' => ['john@example.com', 'jane@example.com'],
            'status' => 'pending',
        ]);

        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson("/api/action-improvements/{$action->id}");

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'status' => 'Success',
                'message' => 'Action improvement retrieved successfully.',
                'data' => [
                    'id' => $action->id,
                    'title' => 'Increase connection pool',
                    'detail' => 'Configure pool to handle 2x peak traffic',
                    'status' => 'pending',
                ],
            ]);
    }

    public function test_show_action_improvement_response_structure(): void
    {
        $incident = Incident::factory()->create();

        $action = ActionImprovement::create([
            'incident_id' => $incident->id,
            'title' => 'Fix timeout',
            'detail' => 'Increase timeout settings',
            'due_date' => '2025-04-01',
            'pic_email' => ['dev@example.com'],
            'status' => 'done',
        ]);

        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson("/api/action-improvements/{$action->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'code',
                'status',
                'message',
                'data' => [
                    'id',
                    'title',
                    'detail',
                    'due_date',
                    'pic_email',
                    'reminder',
                    'reminder_frequency',
                    'status',
                    'created_at',
                    'updated_at',
                ],
            ]);
    }

    public function test_show_action_improvement_with_nonexistent_id_returns_404(): void
    {
        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson('/api/action-improvements/99999');

        $response->assertStatus(404);
    }

    public function test_show_action_improvement_pic_email_is_array(): void
    {
        $incident = Incident::factory()->create();

        $action = ActionImprovement::create([
            'incident_id' => $incident->id,
            'title' => 'Review logs',
            'detail' => 'Check log output',
            'due_date' => '2025-05-01',
            'pic_email' => ['alice@example.com', 'bob@example.com'],
            'status' => 'pending',
        ]);

        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson("/api/action-improvements/{$action->id}");

        $response->assertStatus(200);

        $picEmail = $response->json('data.pic_email');
        $this->assertIsArray($picEmail);
        $this->assertCount(2, $picEmail);
        $this->assertContains('alice@example.com', $picEmail);
        $this->assertContains('bob@example.com', $picEmail);
    }

    // --- Authentication and Authorization ---

    public function test_unauthenticated_user_cannot_list_action_improvements(): void
    {
        $incident = Incident::factory()->create();

        $response = $this->getJson("/api/incidents/{$incident->id}/action-improvements");

        $response->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_show_action_improvement(): void
    {
        $response = $this->getJson('/api/action-improvements/1');

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_list_action_improvements(): void
    {
        $userWithoutPermission = User::factory()->create();
        $token = $userWithoutPermission->createToken('test-token-no-perm')->plainTextToken;

        $incident = Incident::factory()->create();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson("/api/incidents/{$incident->id}/action-improvements");

        $response->assertStatus(403);
    }

    public function test_user_without_permission_cannot_show_action_improvement(): void
    {
        $userWithoutPermission = User::factory()->create();
        $token = $userWithoutPermission->createToken('test-token-no-perm')->plainTextToken;

        $incident = Incident::factory()->create();
        $action = ActionImprovement::create([
            'incident_id' => $incident->id,
            'title' => 'Test action',
            'detail' => 'Test detail',
            'due_date' => '2025-06-01',
            'pic_email' => ['test@example.com'],
            'status' => 'pending',
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson("/api/action-improvements/{$action->id}");

        $response->assertStatus(403);
    }
}
