<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ServiceAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_account_cannot_login_via_api(): void
    {
        $user = User::factory()->create([
            'email' => 'svc-test@service.internal',
            'password' => bcrypt('password123'),
            'is_service_account' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'svc-test@service.internal',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Service accounts cannot use interactive login.');
    }

    public function test_service_account_cannot_access_filament_panel(): void
    {
        $user = User::factory()->create([
            'is_service_account' => true,
        ]);

        $this->assertFalse($user->canAccessPanel(app(\Filament\Panel::class)));
    }

    public function test_regular_user_can_login_via_api(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => bcrypt('password123'),
            'is_service_account' => false,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
    }

    public function test_service_account_can_have_api_tokens(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'access api']);
        $serviceAccount = User::factory()->create([
            'is_service_account' => true,
        ]);
        $serviceAccount->givePermissionTo($permission);

        $token = $serviceAccount->createToken('test-token');

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $this->assertNotEquals(401, $response->getStatusCode());
    }

    public function test_service_account_scope_filters_correctly(): void
    {
        $serviceAccount = User::factory()->create(['is_service_account' => true]);
        $humanUser = User::factory()->create(['is_service_account' => false]);

        $serviceAccounts = User::serviceAccounts()->get();
        $humanUsers = User::humanUsers()->get();

        $this->assertTrue($serviceAccounts->contains($serviceAccount));
        $this->assertFalse($serviceAccounts->contains($humanUser));
        $this->assertTrue($humanUsers->contains($humanUser));
        $this->assertFalse($humanUsers->contains($serviceAccount));
    }

    public function test_login_token_has_explicit_expiry(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);

        $token = $this->user ?? User::where('email', 'test@example.com')->first();
        $latestToken = $token->tokens()->latest()->first();

        $this->assertNotNull($latestToken->expires_at);
        $this->assertTrue($latestToken->expires_at->isFuture());
    }
}
