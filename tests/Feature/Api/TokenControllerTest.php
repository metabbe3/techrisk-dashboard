<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TokenControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(['name' => 'access api']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo($permission);
    }

    public function test_logout_disables_current_token(): void
    {
        $token = $this->user->createToken('test');

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Token revoked successfully.');

        $this->assertNotNull($token->accessToken->fresh()->disabled_at);
    }

    public function test_logout_requires_authentication(): void
    {
        $response = $this->postJson('/api/logout');

        $response->assertStatus(401);
    }

    public function test_token_info_returns_current_token_metadata(): void
    {
        $token = $this->user->createToken('test');
        $token->accessToken->forceFill([
            'expires_at' => now()->addMonths(6),
            'renewal_minutes' => 43200,
            'allowed_endpoints' => ['incidents'],
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/token/info');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'code',
                'status',
                'data' => [
                    'name',
                    'expires_at',
                    'last_used_at',
                    'allowed_endpoints',
                    'abilities',
                    'has_pii_access',
                    'is_expired',
                    'is_disabled',
                ],
            ])
            ->assertJsonPath('data.is_expired', false)
            ->assertJsonPath('data.is_disabled', false)
            ->assertJsonPath('data.has_pii_access', true);
    }

    public function test_token_info_shows_pii_access_as_false_without_scope(): void
    {
        $token = $this->user->createToken('test', ['read']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/token/info');

        $response->assertStatus(200)
            ->assertJsonPath('data.has_pii_access', false);
    }

    public function test_disabled_token_cannot_access_token_info(): void
    {
        $token = $this->user->createToken('test');
        $token->accessToken->forceFill(['disabled_at' => now()])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/token/info');

        $response->assertStatus(401);
    }

    public function test_expired_token_cannot_access_token_info(): void
    {
        $token = $this->user->createToken('test');
        $token->accessToken->forceFill(['expires_at' => now()->subDay()])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/token/info');

        $response->assertStatus(401);
    }
}
