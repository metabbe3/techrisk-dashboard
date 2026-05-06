<?php

namespace Tests\Browser;

use App\Models\Incident;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class IncidentActionsTest extends DuskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--seed' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ])->assignRole('admin');

        Incident::factory()->create(['pic_id' => $user->id]);
    }

    protected function loginAsAdmin(Browser $browser): Browser
    {
        return $browser->visit('/admin/login')
            ->waitFor('[wire\\:model="data.email"]')
            ->type('[wire\\:model="data.email"]', 'admin@example.com')
            ->type('[wire\\:model="data.password"]', 'password')
            ->press('button[type="submit"]')
            ->pause(2000)->assertPathBeginsWith('/admin');
    }

    public function test_edit_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);
            $browser->visit('/admin/incidents/1/edit')
                ->waitForText('Edit');
        });
    }

    public function test_view_page_has_relation_manager_tabs(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);
            $browser->visit('/admin/incidents/1')
                ->waitForText('Status Updates');
        });
    }

    public function test_view_page_shows_action_improvements_tab(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);
            $browser->visit('/admin/incidents/1')
                ->waitForText('Action Improvements');
        });
    }

    public function test_view_page_shows_investigation_documents_tab(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);
            $browser->visit('/admin/incidents/1')
                ->waitForText('Investigation Documents');
        });
    }

    public function test_view_page_shows_audits_tab(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);
            $browser->visit('/admin/incidents/1')
                ->waitForText('Audits');
        });
    }
}
