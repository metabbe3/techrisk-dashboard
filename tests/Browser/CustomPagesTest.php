<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class CustomPagesTest extends DuskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--seed' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);

        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ])->assignRole('admin');
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

    public function test_sidebar_shows_expected_menu_items(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);
            $browser->assertSee('Dashboard')
                ->assertSee('Incidents')
                ->assertSee('Issues');
        });
    }

    public function test_weekly_report_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);
            $browser->visit('/admin/weekly-report')
                ->waitForText('Weekly Report');
        });
    }

    public function test_profile_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);
            $browser->visit('/admin/profile')
                ->waitForText('Profile');
        });
    }

    public function test_profile_shows_user_info(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);
            $browser->visit('/admin/profile')
                ->waitForText('admin@example.com');
        });
    }

    public function test_reporting_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);
            $browser->visit('/admin/reporting')
                ->waitForText('Reporting');
        });
    }
}
