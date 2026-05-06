<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class AuthPagesTest extends DuskTestCase
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

    public function test_root_redirects_to_login(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->assertPathIs('/admin/login');
        });
    }

    public function test_login_page_renders_correctly(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/login')
                ->waitFor('[wire\\:model="data.email"]')
                ->assertPresent('[wire\\:model="data.email"]')
                ->assertPresent('[wire\\:model="data.password"]')
                ->assertSee('Sign in');
        });
    }

    public function test_login_with_valid_admin_credentials(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/login')
                ->waitFor('[wire\\:model="data.email"]')
                ->type('[wire\\:model="data.email"]', 'admin@example.com')
                ->type('[wire\\:model="data.password"]', 'password')
                ->press('button[type="submit"]')
                ->pause(2000)->assertPathBeginsWith('/admin');
        });
    }

    public function test_login_with_invalid_credentials_shows_error(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/login')
                ->waitFor('[wire\\:model="data.email"]')
                ->type('[wire\\:model="data.email"]', 'admin@example.com')
                ->type('[wire\\:model="data.password"]', 'wrong-password')
                ->press('button[type="submit"]')
                ->waitForText('These credentials do not match')
                ->assertPathIs('/admin/login');
        });
    }

    public function test_request_access_page_renders(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/request-access')
                ->waitForText('Request Access')
                ->assertPresent('#name')
                ->assertPresent('#email')
                ->assertPresent('#password')
                ->assertPresent('#reason');
        });
    }

    public function test_submit_access_request_form(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/request-access')
                ->waitForText('Request Access')
                ->type('[wire\\:model.live="name"]', 'Dusk Test User')
                ->type('[wire\\:model.live="email"]', 'dusk-test-'.time().'@example.com')
                ->type('[wire\\:model.live="password"]', 'securepassword')
                ->select('[wire\\:model.live="requested_duration_days"]', '30')
                ->type('[wire\\:model.live="reason"]', 'I need access to review technical risk data for my department testing purposes.')
                ->press('Submit Request')
                ->waitForText('submitted', 15);
        });
    }
}
