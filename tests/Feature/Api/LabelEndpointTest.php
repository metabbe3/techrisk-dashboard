<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LabelEndpointTest extends TestCase
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

    public function test_can_get_all_labels(): void
    {
        Label::create(['name' => 'payment']);
        Label::create(['name' => 'database']);
        Label::create(['name' => 'network']);

        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson('/api/v1/labels');

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'status' => 'Success',
                'message' => 'Labels retrieved successfully.',
            ]);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(3, $data);
        $this->assertContains('payment', $data);
        $this->assertContains('database', $data);
        $this->assertContains('network', $data);
    }

    public function test_labels_returns_empty_array_when_no_labels_exist(): void
    {
        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson('/api/v1/labels');

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'status' => 'Success',
                'message' => 'Labels retrieved successfully.',
            ]);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }

    public function test_labels_response_structure(): void
    {
        Label::create(['name' => 'api']);

        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson('/api/v1/labels');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'code',
                'status',
                'message',
                'data',
            ]);
    }

    public function test_labels_are_cached(): void
    {
        Label::create(['name' => 'cache-test']);

        $response = $this->withHeaders($this->authenticatedHeaders())
            ->getJson('/api/v1/labels');
        $response->assertStatus(200);
        $this->assertContains('cache-test', $response->json('data'));

        $cached = Cache::get('labels');
        $this->assertNotNull($cached);
        $this->assertContains('cache-test', $cached);
    }

    public function test_unauthenticated_user_cannot_access_labels(): void
    {
        $response = $this->getJson('/api/v1/labels');

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_access_labels(): void
    {
        $userWithoutPermission = User::factory()->create();
        $token = $userWithoutPermission->createToken('test-token-no-perm')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/labels');

        $response->assertStatus(403);
    }
}
