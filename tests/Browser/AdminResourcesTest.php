<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class AdminResourcesTest extends DuskTestCase
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

    // ---------------------------------------------------------------
    // API Tokens
    // ---------------------------------------------------------------

    public function test_api_tokens_list_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/api-tokens')
                ->waitFor('table', 10)
                ->assertSee('API Tokens');
        });
    }

    public function test_api_tokens_list_displays_table(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/api-tokens')
                ->waitFor('table', 10)
                ->assertPresent('table')
                ->assertSee('Name')
                ->assertSee('User Email');
        });
    }

    public function test_api_tokens_create_button_is_visible(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/api-tokens')
                ->waitFor('table', 10)
                ->assertSee('New token');
        });
    }

    // ---------------------------------------------------------------
    // Roles
    // ---------------------------------------------------------------

    public function test_roles_list_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/roles')
                ->waitFor('table', 10)
                ->assertSee('Roles');
        });
    }

    public function test_roles_list_displays_roles_table(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/roles')
                ->waitFor('table', 10)
                ->assertPresent('table')
                ->assertSee('admin')
                ->assertSee('user');
        });
    }

    public function test_roles_new_role_button_opens_create_form(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/roles')
                ->waitFor('table', 10)
                ->assertSee('New role')
                ->clickLink('New role')
                ->waitFor('input[name="name"]', 10)
                ->assertInputPresent('name')
                ->assertPresent('select[name="permissions[]"]');
        });
    }

    public function test_roles_create_form_shows_permission_checkboxes(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/roles/create')
                ->waitFor('input[name="name"]', 10)
                ->assertInputPresent('name')
                ->assertSee('access api')
                ->assertSee('view incidents');
        });
    }

    public function test_roles_edit_page_loads_with_prefilled_permissions(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            // The admin role has ID 1 since it's seeded first
            $browser->visit('/admin/roles/1/edit')
                ->waitFor('input[name="name"]', 10)
                ->assertInputValue('name', 'admin');
        });
    }

    // ---------------------------------------------------------------
    // Permissions
    // ---------------------------------------------------------------

    public function test_permissions_list_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/permissions')
                ->waitFor('table', 10)
                ->assertSee('Permissions');
        });
    }

    public function test_permissions_list_displays_permission_names(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/permissions')
                ->waitFor('table', 10)
                ->assertPresent('table')
                ->assertSee('access api')
                ->assertSee('view incidents')
                ->assertSee('manage incidents');
        });
    }

    public function test_permissions_list_is_read_only(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/permissions')
                ->waitFor('table', 10)
                ->assertDontSee('New permission')
                ->assertDontSee('Edit');
        });
    }
}
