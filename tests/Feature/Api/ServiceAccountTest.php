<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/*
 * REMOVED CONTRACT: /api/login deleted (token-only API). Login-dependent tests
 * removed 2026-09-28; token/panel/scope tests retained.
 */
class ServiceAccountTest extends TestCase
{
    use RefreshDatabase;


    public function test_service_account_cannot_access_filament_panel(): void
    {
        $user = User::factory()->create([
            'is_service_account' => true,
        ]);

        $this->assertFalse($user->canAccessPanel(app(\Filament\Panel::class)));
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

}
