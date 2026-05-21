<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApiEndpoint;
use App\Models\ApiAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected User $userWithAccess;

    protected User $userWithoutAccess;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'access api']);

        $this->userWithAccess = User::factory()->create();
        $this->userWithAccess->givePermissionTo($permission);

        $this->userWithoutAccess = User::factory()->create();
    }

    // =========================================================================
    // CheckApiAccess Middleware Tests
    // =========================================================================

    public function test_user_with_access_api_permission_passes_middleware(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        // Should not be 403 - the middleware passed
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_user_without_access_api_permission_gets_403(): void
    {
        $token = $this->userWithoutAccess->createToken('test', ['*']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_gets_401(): void
    {
        $response = $this->getJson('/api/v1/incidents');

        $response->assertStatus(401);
    }

    // =========================================================================
    // CheckApiTokenAccess Middleware Tests
    // =========================================================================

    public function test_active_token_passes_middleware(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $this->assertNotEquals(401, $response->getStatusCode());
    }

    public function test_inactive_token_returns_401_and_is_disabled(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);
        $tokenId = $token->accessToken->id;

        $tokenModel = PersonalAccessToken::find($tokenId);
        $tokenModel->forceFill([
            'last_used_at' => now()->subDays(91),
            'expires_at' => now()->addMonths(6),
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $response->assertStatus(401)
            ->assertJson(fn ($json) => $json->where('status', 'Error')->etc());

        $this->assertNotNull(PersonalAccessToken::find($tokenId)?->disabled_at);
    }

    public function test_token_with_recent_last_used_is_valid(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);
        $tokenModel = PersonalAccessToken::find($token->accessToken->id);
        $tokenModel->forceFill([
            'last_used_at' => now()->subDays(5),
            'expires_at' => now()->addMonths(6),
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $this->assertNotEquals(401, $response->getStatusCode());
    }

    public function test_disabled_token_returns_401(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);
        $tokenModel = PersonalAccessToken::find($token->accessToken->id);
        $tokenModel->forceFill(['disabled_at' => now()])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $response->assertStatus(401);
    }

    public function test_expired_token_returns_401(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);
        $tokenModel = PersonalAccessToken::find($token->accessToken->id);
        $tokenModel->forceFill(['expires_at' => now()->subDay()])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $response->assertStatus(401);
    }

    public function test_auto_renewal_extends_token_expiry(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);
        $tokenModel = PersonalAccessToken::find($token->accessToken->id);
        $originalExpiresAt = now()->addMonths(6);
        $tokenModel->forceFill([
            'last_used_at' => now()->subDays(5),
            'expires_at' => $originalExpiresAt,
            'renewal_minutes' => 43200, // 30 days
        ])->save();

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $tokenModel->refresh();
        $this->assertTrue($tokenModel->expires_at->gt($originalExpiresAt));
    }

    public function test_never_used_token_is_not_expired(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);

        // Token with null last_used_at should not be expired
        $tokenModel = PersonalAccessToken::find($token->accessToken->id);
        $this->assertNull($tokenModel->last_used_at);

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $this->assertNotEquals(401, $response->getStatusCode());
    }

    public function test_token_with_matching_allowed_endpoint_passes(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);

        // Set allowed_endpoints to incidents only
        $tokenModel = PersonalAccessToken::find($token->accessToken->id);
        $tokenModel->forceFill([
            'allowed_endpoints' => json_encode(['incidents']),
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_token_with_non_matching_allowed_endpoint_gets_403(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);

        // Set allowed_endpoints to labels only - not incidents
        $tokenModel = PersonalAccessToken::find($token->accessToken->id);
        $tokenModel->forceFill([
            'allowed_endpoints' => json_encode(['labels']),
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'This token does not have permission to access this endpoint.');
    }

    public function test_token_with_empty_allowed_endpoints_passes_all(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);

        // Empty allowed_endpoints - backward compatible, should allow all
        $tokenModel = PersonalAccessToken::find($token->accessToken->id);
        $tokenModel->forceFill([
            'allowed_endpoints' => null,
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_last_used_at_is_updated_after_valid_request(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);
        $tokenModel = PersonalAccessToken::find($token->accessToken->id);

        $this->assertNull($tokenModel->last_used_at);

        // Sanctum updates last_used_at during auth:sanctum middleware
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $tokenModel->refresh();
        $this->assertNotNull($tokenModel->last_used_at);
        // Verify it was set recently (within last 5 seconds)
        $this->assertLessThan(5, now()->diffInSeconds($tokenModel->last_used_at));
    }

    public function test_request_without_token_returns_401_from_token_middleware(): void
    {
        // If there is no currentAccessToken, the middleware returns 401
        // But this scenario is already handled by auth:sanctum which returns 401
        // before reaching check.api.token.access
        $response = $this->getJson('/api/v1/incidents');

        $response->assertStatus(401);
    }

    public function test_token_with_multiple_allowed_endpoints(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);

        // Set multiple allowed endpoints
        $tokenModel = PersonalAccessToken::find($token->accessToken->id);
        $tokenModel->forceFill([
            'allowed_endpoints' => json_encode(['incidents', 'labels', 'incident-types']),
        ])->save();

        // Should access incidents
        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');
        $this->assertNotEquals(403, $response->getStatusCode());

        // Should access labels
        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/labels');
        $this->assertNotEquals(403, $response->getStatusCode());

        // Should NOT access ai/export endpoint (not in allowed list, has 'ai-export' endpoint restriction)
        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/ai/export');
        $response->assertStatus(403);
    }

    // =========================================================================
    // ApiAuditLogger Middleware Tests
    // =========================================================================

    public function test_response_includes_trace_id_header(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $this->assertTrue($response->headers->has('X-Trace-ID'));
        $this->assertNotEmpty($response->headers->get('X-Trace-ID'));
    }

    public function test_custom_trace_id_in_request_is_preserved(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);
        $customTraceId = 'custom-trace-12345';

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->withHeader('X-Trace-ID', $customTraceId)
            ->getJson('/api/v1/incidents');

        $response->assertSuccessful();
        $this->assertEquals($customTraceId, $response->headers->get('X-Trace-ID'));
    }

    public function test_audit_log_is_created_for_valid_requests(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        // Queue is sync in tests, so job executes immediately
        $this->assertDatabaseHas('api_audit_logs', [
            'user_id' => $this->userWithAccess->id,
            'user_email' => $this->userWithAccess->email,
            'method' => 'GET',
        ]);
    }

    public function test_health_endpoint_is_not_logged(): void
    {
        $this->getJson('/api/health');

        $this->assertDatabaseEmpty('api_audit_logs');
    }

    public function test_health_endpoint_at_root_is_not_logged(): void
    {
        // The /up route (health check registered in bootstrap/app.php)
        $this->getJson('/up');

        $this->assertDatabaseEmpty('api_audit_logs');
    }

    public function test_audit_log_captures_request_details(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents?per_page=10');

        $auditLog = ApiAuditLog::first();

        $this->assertNotNull($auditLog);
        $this->assertNotNull($auditLog->trace_id);
        $this->assertNotNull($auditLog->request_id);
        $this->assertEquals('GET', $auditLog->method);
        $this->assertEquals($this->userWithAccess->id, $auditLog->user_id);
        $this->assertStringContainsString('incidents', $auditLog->endpoint);
    }

    public function test_audit_log_captures_response_details(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $auditLog = ApiAuditLog::first();

        $this->assertNotNull($auditLog);
        $this->assertEquals(200, $auditLog->response_status);
        $this->assertNotNull($auditLog->response_timestamp);
        $this->assertGreaterThanOrEqual(0, $auditLog->response_time_ms);
    }

    // =========================================================================
    // Exception Handling Tests
    // =========================================================================

    public function test_404_for_nonexistent_api_route_returns_json(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/nonexistent-route');

        $response->assertStatus(404);
        $response->assertJsonPath('status', 'Error');
        $response->assertJsonPath('code', 404);

        $contentType = $response->headers->get('Content-Type', '');
        $this->assertTrue(
            str_contains($contentType, 'json'),
            "Expected JSON Content-Type but got: {$contentType}"
        );
    }

    public function test_404_for_missing_resource_returns_json(): void
    {
        $token = $this->userWithAccess->createToken('test', ['*']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents/99999');

        $response->assertStatus(404);
        $response->assertJsonPath('status', 'Error');

        $contentType = $response->headers->get('Content-Type', '');
        $this->assertTrue(
            str_contains($contentType, 'json'),
            "Expected JSON Content-Type but got: {$contentType}"
        );
    }

    public function test_401_for_unauthenticated_returns_json(): void
    {
        $response = $this->getJson('/api/v1/incidents');

        $response->assertStatus(401);
        $response->assertJsonPath('status', 'Error');
        $response->assertJsonPath('code', 401);
        $response->assertJsonPath('message', 'Unauthenticated.');

        $contentType = $response->headers->get('Content-Type', '');
        $this->assertTrue(
            str_contains($contentType, 'json'),
            "Expected JSON Content-Type but got: {$contentType}"
        );
    }

    public function test_403_for_unauthorized_returns_json(): void
    {
        $token = $this->userWithoutAccess->createToken('test', ['*']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $response->assertStatus(403);
        $response->assertJsonPath('status', 'Error');

        $contentType = $response->headers->get('Content-Type', '');
        $this->assertTrue(
            str_contains($contentType, 'json'),
            "Expected JSON Content-Type but got: {$contentType}"
        );
    }

    public function test_422_for_validation_errors_returns_json_with_errors(): void
    {
        $response = $this->postJson('/api/login', []);

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'Error');
        $response->assertJsonPath('code', 422);
        $response->assertJsonStructure(['errors']);
        $this->assertArrayHasKey('email', $response->json('errors'));
        $this->assertArrayHasKey('password', $response->json('errors'));

        $contentType = $response->headers->get('Content-Type', '');
        $this->assertTrue(
            str_contains($contentType, 'json'),
            "Expected JSON Content-Type but got: {$contentType}"
        );
    }

    // =========================================================================
    // Sensitive Data Filtering Tests (via ApiAuditLogger)
    // =========================================================================

    public function test_sensitive_fields_are_filtered_in_audit_log(): void
    {
        // Use the login endpoint which accepts POST and includes password in body
        // The audit logger should filter the password field
        $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'secret123',
            'api_token' => 'some-token-value',
        ]);

        // The audit log should exist for this POST request
        $auditLog = ApiAuditLog::query()
            ->where('method', 'POST')
            ->first();

        $this->assertNotNull($auditLog, 'Audit log should be created for POST request');
        $this->assertNotNull($auditLog->request_body, 'Request body should be captured');

        $body = $auditLog->request_body;
        $this->assertEquals('[REDACTED]', $body['password'], 'Password should be fully redacted');
        $this->assertEquals('[REDACTED]', $body['api_token'], 'API token should be fully redacted');
        // Email is partially redacted (showing first/last 2 chars)
        $this->assertNotEquals('test@example.com', $body['email'], 'Email should be partially redacted');
        $this->assertStringContainsString('*', $body['email'], 'Email should contain redaction markers');
    }

    // =========================================================================
    // ApiEndpoint Enum Tests
    // =========================================================================

    public function test_api_endpoint_matches_route(): void
    {
        $this->assertTrue(ApiEndpoint::INCIDENTS->matchesRoute('v1/incidents'));
        $this->assertTrue(ApiEndpoint::INCIDENTS->matchesRoute('api/v1/incidents'));
        $this->assertTrue(ApiEndpoint::LABELS->matchesRoute('v1/labels'));
        $this->assertTrue(ApiEndpoint::INCIDENT_TYPES->matchesRoute('v1/incident-types'));
        $this->assertTrue(ApiEndpoint::AI_EXPORT->matchesRoute('v1/ai/export'));
    }

    public function test_api_endpoint_does_not_match_wrong_route(): void
    {
        $this->assertFalse(ApiEndpoint::INCIDENTS->matchesRoute('v1/labels'));
        $this->assertFalse(ApiEndpoint::LABELS->matchesRoute('v1/incidents'));
    }

    public function test_api_endpoint_all_returns_all_values(): void
    {
        $all = ApiEndpoint::all();

        $this->assertCount(count(ApiEndpoint::cases()), $all);
        $this->assertContains('incidents', $all);
        $this->assertContains('labels', $all);
        $this->assertContains('incident-types', $all);
    }
}
