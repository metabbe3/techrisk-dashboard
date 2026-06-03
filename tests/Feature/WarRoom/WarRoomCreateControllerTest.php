<?php

namespace Tests\Feature\WarRoom;

use App\Models\Incident;
use App\Models\User;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WarRoomCreateControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access war room']);
        $this->user->givePermissionTo('access war room');
    }

    public function test_create_session_success(): void
    {
        $incident = Incident::factory()->create();

        // Build a session model without persisting to avoid the controller's
        // duplicate-session check. Set a UUID manually so the response can read ->id.
        $session = new WarRoomSession([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'incident_id' => $incident->id,
            'status' => 'pending',
            'title' => 'Test Session',
        ]);
        // Mark as exists so the JSON response reads attributes correctly
        $session->exists = true;

        $mockService = Mockery::mock(WarRoomService::class);
        $mockService->shouldReceive('createSession')
            ->once()
            ->withArgs(function ($incidentIds, $user, $selectedAgents) use ($incident) {
                return $incidentIds === [$incident->id]
                    && $user->is($this->user)
                    && $selectedAgents === ['sre', 'tech_risk'];
            })
            ->andReturn($session);

        $this->app->instance(WarRoomService::class, $mockService);

        $response = $this->actingAs($this->user)
            ->postJson('/admin/war-room/sessions', [
                'incident_ids' => [$incident->id],
                'selected_agents' => ['sre', 'tech_risk'],
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'status', 'title'])
            ->assertJson([
                'status' => 'pending',
                'title' => 'Test Session',
            ]);
    }

    public function test_create_session_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/admin/war-room/sessions', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['incident_ids', 'selected_agents']);
    }

    public function test_create_session_requires_authentication(): void
    {
        $response = $this->postJson('/admin/war-room/sessions', [
            'incident_ids' => ['non-existent'],
            'selected_agents' => ['sre'],
        ]);

        $response->assertStatus(401);
    }

    public function test_create_session_validates_incident_ids(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/admin/war-room/sessions', [
                'incident_ids' => [99999],
                'selected_agents' => ['sre'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['incident_ids.0']);
    }

    public function test_create_session_validates_incident_ids_must_be_array(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/admin/war-room/sessions', [
                'incident_ids' => 'not-an-array',
                'selected_agents' => ['sre'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['incident_ids']);
    }

    public function test_create_session_validates_selected_agents_must_be_array(): void
    {
        $incident = Incident::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson('/admin/war-room/sessions', [
                'incident_ids' => [$incident->id],
                'selected_agents' => 'not-an-array',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['selected_agents']);
    }

    public function test_create_session_validates_max_rounds_range(): void
    {
        $incident = Incident::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson('/admin/war-room/sessions', [
                'incident_ids' => [$incident->id],
                'selected_agents' => ['sre'],
                'max_rounds' => 10,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['max_rounds']);
    }

    public function test_create_session_returns_409_for_existing_active_session(): void
    {
        $incident = Incident::factory()->create();

        // Create an existing non-failed session for this incident
        $existingSession = WarRoomSession::factory()->create([
            'user_id' => $this->user->id,
            'incident_id' => $incident->id,
            'status' => 'pending',
        ]);

        // Attach the incident via the pivot table so the forIncident scope finds it
        $existingSession->incidents()->sync([$incident->id]);

        $response = $this->actingAs($this->user)
            ->postJson('/admin/war-room/sessions', [
                'incident_ids' => [$incident->id],
                'selected_agents' => ['sre'],
            ]);

        $response->assertStatus(409)
            ->assertJson(['message' => 'This incident already has an active discussion.'])
            ->assertJsonStructure(['existing_session' => ['id', 'title', 'status', 'created_at']]);
    }

    public function test_create_session_allows_new_session_for_failed_existing(): void
    {
        $incident = Incident::factory()->create();

        // A failed session should NOT block creating a new one
        $failedSession = WarRoomSession::factory()->failed()->create([
            'user_id' => $this->user->id,
            'incident_id' => $incident->id,
        ]);
        $failedSession->incidents()->sync([$incident->id]);

        $session = new WarRoomSession([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'incident_id' => $incident->id,
            'status' => 'pending',
            'title' => 'New Session After Failure',
        ]);
        $session->exists = true;

        $mockService = Mockery::mock(WarRoomService::class);
        $mockService->shouldReceive('createSession')
            ->once()
            ->andReturn($session);

        $this->app->instance(WarRoomService::class, $mockService);

        $response = $this->actingAs($this->user)
            ->postJson('/admin/war-room/sessions', [
                'incident_ids' => [$incident->id],
                'selected_agents' => ['sre'],
            ]);

        $response->assertStatus(201);
    }

    public function test_create_session_returns_429_when_rate_limited(): void
    {
        $incident = Incident::factory()->create();

        $mockService = Mockery::mock(WarRoomService::class);
        $mockService->shouldReceive('createSession')
            ->once()
            ->andThrow(new \RuntimeException('Daily session limit reached (10 sessions per day).'));

        $this->app->instance(WarRoomService::class, $mockService);

        $response = $this->actingAs($this->user)
            ->postJson('/admin/war-room/sessions', [
                'incident_ids' => [$incident->id],
                'selected_agents' => ['sre'],
            ]);

        $response->assertStatus(429)
            ->assertJson(['message' => 'Daily session limit reached (10 sessions per day).']);
    }

    public function test_create_session_returns_500_on_unexpected_error(): void
    {
        $incident = Incident::factory()->create();

        $mockService = Mockery::mock(WarRoomService::class);
        $mockService->shouldReceive('createSession')
            ->once()
            ->andThrow(new \InvalidArgumentException('Unexpected AI service failure'));

        $this->app->instance(WarRoomService::class, $mockService);

        $response = $this->actingAs($this->user)
            ->postJson('/admin/war-room/sessions', [
                'incident_ids' => [$incident->id],
                'selected_agents' => ['sre'],
            ]);

        $response->assertStatus(500)
            ->assertJson(['message' => 'Unexpected AI service failure']);
    }

    public function test_create_session_passes_optional_parameters(): void
    {
        $incident = Incident::factory()->create();

        $session = new WarRoomSession([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'incident_id' => $incident->id,
            'status' => 'pending',
            'title' => 'Custom Session',
        ]);
        $session->exists = true;

        $mockService = Mockery::mock(WarRoomService::class);
        $mockService->shouldReceive('createSession')
            ->once()
            ->withArgs(function ($incidentIds, $user, $selectedAgents, $maxRounds, $model, $moderatorModel, $enableWebSearch, $deepAnalysis, $userInstructions) {
                return $maxRounds === 3
                    && $model === 'gpt-4'
                    && $moderatorModel === 'gpt-4-turbo'
                    && $enableWebSearch === true
                    && $deepAnalysis === false
                    && $userInstructions === 'Focus on root cause';
            })
            ->andReturn($session);

        $this->app->instance(WarRoomService::class, $mockService);

        $response = $this->actingAs($this->user)
            ->postJson('/admin/war-room/sessions', [
                'incident_ids' => [$incident->id],
                'selected_agents' => ['sre', 'tech_risk'],
                'max_rounds' => 3,
                'model' => 'gpt-4',
                'moderator_model' => 'gpt-4-turbo',
                'enable_web_search' => true,
                'deep_analysis' => false,
                'user_instructions' => 'Focus on root cause',
            ]);

        $response->assertStatus(201);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
