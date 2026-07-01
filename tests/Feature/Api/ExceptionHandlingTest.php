<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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

    public function test_validation_failure_returns_unified_envelope_with_errors(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/incidents?severity=NotARealSeverity');

        $response->assertStatus(422);
        $response->assertJsonPath('code', 422);
        $response->assertJsonPath('status', 'Error');
        $response->assertJsonPath('data', null);
        $response->assertJsonStructure([
            'code',
            'status',
            'message',
            'data',
            'errors' => ['severity'],
        ]);
    }

    public function test_unauthenticated_request_returns_unified_envelope(): void
    {
        $response = $this->getJson('/api/v1/incidents');

        $response->assertStatus(401);
        $response->assertJsonPath('status', 'Error');
        $response->assertJsonPath('message', 'Unauthenticated.');
        $response->assertJsonPath('data', null);
    }

    public function test_server_error_does_not_leak_exception_message(): void
    {
        // Even with debug on, the raw exception message must never reach the client.
        config(['app.debug' => true]);

        Route::get('/api/_test/throw', fn () => throw new \RuntimeException('SECRET_INTERNAL detail password=hunter2'));

        $response = $this->getJson('/api/_test/throw');

        $response->assertStatus(500);
        $response->assertJsonPath('status', 'Error');
        $response->assertJsonPath('message', 'Internal server error.');
        $response->assertJsonPath('data', null);
        $this->assertStringNotContainsString('hunter2', $response->getContent());
    }
}
