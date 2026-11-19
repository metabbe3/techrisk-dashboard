<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Users-page "Org chart" row action: the manager chain up to 3 levels
 * (loop-guarded) + everyone sharing the same direct lead.
 */
class UserOrgChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_shows_three_levels_up_and_teammates(): void
    {
        $four = User::factory()->create(['name' => 'Zed Fourup']);            // 4 up — out of view
        $three = User::factory()->create(['name' => 'Zed Threeup', 'manager_id' => $four->id]);
        $two = User::factory()->create(['name' => 'Zed Twoup', 'manager_id' => $three->id]);
        $lead = User::factory()->create(['name' => 'Zed Lead', 'manager_id' => $two->id]);
        $user = User::factory()->create(['name' => 'Zed Focus', 'manager_id' => $lead->id]);
        User::factory()->create(['name' => 'Zed Mate', 'manager_id' => $lead->id, 'job_title' => 'Analyst']);

        // Hard cap at 3 levels — the page's own table also renders user names,
        // so the cap is asserted on the chain builder, not via assertDontSee.
        $this->assertCount(3, \App\Filament\Resources\UserResource::orgChain($user));
        $this->assertSame('Zed Lead', \App\Filament\Resources\UserResource::orgChain($user)[0]->name);
        $this->assertSame('Zed Fourup', \App\Filament\Resources\UserResource::orgChain($lead)[2]->name, 'Chain keeps climbing per focal user');

        $viewer = User::factory()->create()->givePermissionTo('view users');

        Livewire::actingAs($viewer)
            ->test(ListUsers::class)
            ->mountTableAction('orgChart', $user)
            ->assertSuccessful()
            ->assertSee('Direct lead')
            ->assertSee('Zed Lead')
            ->assertSee('Zed Twoup')
            ->assertSee('Zed Threeup')
            ->assertSee('Zed Mate');
    }

    public function test_user_without_manager_gets_a_hint(): void
    {
        $user = User::factory()->create(['name' => 'Zed Orphan']);
        $viewer = User::factory()->create()->givePermissionTo('view users');

        Livewire::actingAs($viewer)
            ->test(ListUsers::class)
            ->mountTableAction('orgChart', $user)
            ->assertSuccessful()
            ->assertSee('No manager linked');
    }
}
