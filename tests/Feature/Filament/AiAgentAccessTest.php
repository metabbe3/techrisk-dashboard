<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AiAgentResource;
use App\Filament\Resources\AiAgentRunResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AiAgentAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Panel access (canAccessPanel) needs 'access dashboard' or the admin role —
     * grant it in both tests so the 403s below test the RESOURCE gate, not the
     * panel middleware's.
     */
    private function panelUser(array $permissions): User
    {
        Permission::firstOrCreate(['name' => 'access dashboard']);
        Permission::firstOrCreate(['name' => 'manage api tokens']);

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_user_with_permission_can_view_agents_and_runs(): void
    {
        $user = $this->panelUser(['access dashboard', 'manage api tokens']);

        $this->actingAs($user)
            ->get(AiAgentResource::getUrl('index'))
            ->assertSuccessful();

        $this->actingAs($user)
            ->get(AiAgentRunResource::getUrl('index'))
            ->assertSuccessful();
    }

    public function test_user_without_permission_gets_forbidden(): void
    {
        // Can enter the panel, but lacks the agents permission.
        $user = $this->panelUser(['access dashboard']);

        $this->actingAs($user)
            ->get(AiAgentResource::getUrl('index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(AiAgentRunResource::getUrl('index'))
            ->assertForbidden();
    }
}
