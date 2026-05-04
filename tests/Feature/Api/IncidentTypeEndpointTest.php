<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\IncidentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class IncidentTypeEndpointTest extends TestCase
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

    public function test_can_get_all_incident_types(): void
    {
        IncidentType::factory()->create(['name' => 'Network Issue']);
        IncidentType::factory()->create(['name' => 'Server Error']);
        IncidentType::factory()->create(['name' => 'Database Timeout']);

        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson('/api/v1/incident-types');

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'status' => 'Success',
                'message' => 'Incident types retrieved successfully.',
            ]);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(3, $data);
        $this->assertContains('Network Issue', $data);
        $this->assertContains('Server Error', $data);
        $this->assertContains('Database Timeout', $data);
    }

    public function test_incident_types_returns_empty_array_when_none_exist(): void
    {
        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson('/api/v1/incident-types');

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'status' => 'Success',
                'message' => 'Incident types retrieved successfully.',
            ]);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }

    public function test_incident_types_response_structure(): void
    {
        IncidentType::factory()->create(['name' => 'API Failure']);

        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson('/api/v1/incident-types');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'code',
                'status',
                'message',
                'data',
            ]);
    }

    public function test_incident_types_are_cached(): void
    {
        IncidentType::factory()->create(['name' => 'cache-test-type']);

        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson('/api/v1/incident-types');
        $response->assertStatus(200);
        $this->assertContains('cache-test-type', $response->json('data'));

        $cached = Cache::get('incident_types');
        $this->assertNotNull($cached);
        $this->assertContains('cache-test-type', $cached);
    }

    public function test_unauthenticated_user_cannot_access_incident_types(): void
    {
        $response = $this->getJson('/api/v1/incident-types');

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_access_incident_types(): void
    {
        $userWithoutPermission = User::factory()->create();
        $token = $userWithoutPermission->createToken('test-token-no-perm')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/incident-types');

        $response->assertStatus(403);
    }
}
