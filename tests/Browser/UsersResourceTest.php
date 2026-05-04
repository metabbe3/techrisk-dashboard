<?php

namespace Tests\Browser;

use App\Models\AccessRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class UsersResourceTest extends DuskTestCase
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
            ->waitFor('input[name="email"]')
            ->type('input[name="email"]', 'admin@example.com')
            ->type('input[name="password"]', 'password')
            ->press('button[type="submit"]')
            ->waitForLocation('/admin')
            ->assertPathIs('/admin');
    }

    protected function createAccessRequest(string $status, string $label): AccessRequest
    {
        return AccessRequest::create([
            'id' => Str::uuid()->toString(),
            'name' => $label.' User',
            'email' => Str::lower($label).'_'.time().'_'.Str::random(5).'@example.com',
            'password' => bcrypt('password'),
            'requested_duration_days' => 30,
            'requested_years' => [(int) date('Y')],
            'reason' => "Testing {$status} status display",
            'status' => $status,
        ]);
    }

    protected function uniqueEmail(string $prefix): string
    {
        return $prefix.'_'.time().'_'.Str::random(5).'@example.com';
    }

    public function test_users_list_page_loads_with_records(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/users')
                ->waitFor('table', 10)
                ->assertSee('Users')
                ->assertPresent('table')
                ->assertSee('admin@example.com');
        });
    }

    public function test_new_user_button_opens_creation_form(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/users')
                ->waitFor('table', 10)
                ->assertSee('New user')
                ->clickLink('New user')
                ->waitForLocation('/admin/users/create')
                ->assertPathIs('/admin/users/create')
                ->assertPresent('input[name="name"]')
                ->assertPresent('input[name="email"]')
                ->assertPresent('input[name="password"]');
        });
    }

    public function test_create_user_with_valid_data(): void
    {
        $uniqueEmail = $this->uniqueEmail('test_dusk');

        $this->browse(function (Browser $browser) use ($uniqueEmail) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/users/create')
                ->waitFor('input[name="name"]', 10)
                ->type('input[name="name"]', 'Dusk Test User')
                ->type('input[name="email"]', $uniqueEmail)
                ->type('input[name="password"]', 'SecurePass123!')
                ->press('button[type="submit"]')
                ->waitForLocation('/admin/users')
                ->assertPathIs('/admin/users')
                ->assertSee('Dusk Test User')
                ->assertSee($uniqueEmail);
        });
    }

    public function test_edit_user_page_loads_with_pre_filled_data(): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => $this->uniqueEmail('edit_test'),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/users')
                ->waitFor('table', 10)
                ->assertSee('Original Name')
                ->clickLink('Original Name')
                ->waitForLocation('/admin/users/'.$user->id.'/edit')
                ->assertPathIs('/admin/users/'.$user->id.'/edit')
                ->assertInputValue('input[name="name"]', 'Original Name')
                ->assertInputValue('input[name="email"]', $user->email);
        });
    }

    public function test_edit_user_can_modify_name_and_save(): void
    {
        $user = User::factory()->create([
            'name' => 'Before Edit',
            'email' => $this->uniqueEmail('save_test'),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/users/'.$user->id.'/edit')
                ->waitFor('input[name="name"]', 10)
                ->assertInputValue('input[name="name"]', 'Before Edit')
                ->type('input[name="name"]', 'After Edit')
                ->press('button[type="submit"]')
                ->waitForLocation('/admin/users')
                ->assertPathIs('/admin/users')
                ->assertSee('After Edit');
        });
    }

    public function test_api_tokens_relation_manager_visible_on_edit_page(): void
    {
        $user = User::factory()->create([
            'name' => 'Token Test User',
            'email' => $this->uniqueEmail('tokens'),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/users/'.$user->id.'/edit')
                ->waitFor('input[name="name"]', 10)
                ->assertSee('API Tokens');
        });
    }

    public function test_access_requests_list_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/access-requests')
                ->waitFor('table', 10)
                ->assertSee('Access requests');
        });
    }

    public function test_access_requests_show_pending_status(): void
    {
        $this->createAccessRequest('pending', 'Pending');

        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/access-requests')
                ->waitFor('table', 10)
                ->assertSee('Pending');
        });
    }

    public function test_access_requests_show_approved_status(): void
    {
        $this->createAccessRequest('approved', 'Approved');

        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/access-requests')
                ->waitFor('table', 10)
                ->assertSee('Approved');
        });
    }

    public function test_access_requests_show_rejected_status(): void
    {
        $this->createAccessRequest('rejected', 'Rejected');

        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/access-requests')
                ->waitFor('table', 10)
                ->assertSee('Rejected');
        });
    }

    public function test_approve_reject_buttons_visible_on_pending_requests(): void
    {
        $this->createAccessRequest('pending', 'Actions Test');

        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/access-requests')
                ->waitFor('table', 10)
                ->assertSee('Approve')
                ->assertSee('Reject');
        });
    }
}
