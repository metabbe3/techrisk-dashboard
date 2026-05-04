<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ExceptionHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');
        $this->token = $user->createToken('test-token')->plainTextToken;
    }

    public function test_missing_incident_returns_json_404(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/incidents/99999');

        $response->assertStatus(404);
        // Verify it returns JSON (not HTML) even for route-model binding failures
        $contentType = $response->headers->get('Content-Type', '');
        $this->assertTrue(
            str_contains($contentType, 'json'),
            "Expected JSON Content-Type but got: {$contentType}"
        );
    }

    public function test_nonexistent_api_route_returns_json(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/nonexistent-route');

        $response->assertStatus(404);
        // Should be JSON, not HTML
        $contentType = $response->headers->get('Content-Type', '');
        $this->assertTrue(
            str_contains($contentType, 'json'),
            "Expected JSON Content-Type but got: {$contentType}"
        );
    }
}
