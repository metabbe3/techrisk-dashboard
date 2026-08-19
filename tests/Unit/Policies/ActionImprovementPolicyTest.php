<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\ActionImprovement;
use App\Models\User;
use App\Policies\ActionImprovementPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ActionImprovementPolicyTest extends TestCase
{
    use RefreshDatabase;

    private ActionImprovementPolicy $policy;

    private ActionImprovement $record;

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie caches permissions per-request; forget so RefreshDatabase state is seen.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'manage incidents']);
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'access api']);

        $this->policy = new ActionImprovementPolicy;
        $this->record = new ActionImprovement; // policy methods never read the instance
    }

    public function test_view_any_is_allowed_for_panel_users_who_can_view_incidents(): void
    {
        $user = User::factory()->create()->givePermissionTo('view incidents');

        $this->assertTrue($this->policy->viewAny($user));
        $this->assertTrue($this->policy->view($user, $this->record));
    }

    public function test_view_any_is_allowed_for_api_users(): void
    {
        $user = User::factory()->create()->givePermissionTo('access api');

        $this->assertTrue($this->policy->viewAny($user));
        $this->assertTrue($this->policy->view($user, $this->record));
    }

    public function test_view_any_is_denied_without_view_or_api_permission(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->policy->viewAny($user));
        $this->assertFalse($this->policy->view($user, $this->record));
    }

    public function test_create_is_allowed_when_user_can_manage_incidents(): void
    {
        $user = User::factory()->create()->givePermissionTo('manage incidents');

        $this->assertTrue($this->policy->create($user));
    }

    public function test_create_is_denied_without_manage_incidents(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->policy->create($user));
    }

    public function test_update_is_allowed_when_user_can_manage_incidents(): void
    {
        $user = User::factory()->create()->givePermissionTo('manage incidents');

        $this->assertTrue($this->policy->update($user, $this->record));
    }

    public function test_delete_is_allowed_when_user_can_manage_incidents(): void
    {
        $user = User::factory()->create()->givePermissionTo('manage incidents');

        $this->assertTrue($this->policy->delete($user, $this->record));
    }

    public function test_update_and_delete_are_denied_without_manage_incidents(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->policy->update($user, $this->record));
        $this->assertFalse($this->policy->delete($user, $this->record));
    }
}
