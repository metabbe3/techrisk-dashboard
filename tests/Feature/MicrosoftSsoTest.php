<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Support\PanelHome;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Microsoft (Entra ID) SSO: JIT-creates role-less users on first login (the
 * canAccessPanel() gate keeps them out until an admin grants a role), while
 * email-matched existing users keep their current access untouched.
 */
class MicrosoftSsoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.microsoft', [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost:8000/auth/microsoft/callback',
            'tenant' => 'test-tenant',
        ]);
    }

    private function mockMicrosoftUser(string $email, string $name = 'New User'): void
    {
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn(new class($email, $name)
        {
            public function __construct(private string $email, private string $name) {}

            public function user(): object
            {
                return new class($this->email, $this->name)
                {
                    public function __construct(private string $email, private string $name) {}

                    public function getEmail(): string
                    {
                        return $this->email;
                    }

                    public function getName(): string
                    {
                        return $this->name;
                    }
                };
            }
        });
    }

    public function test_redirect_route_points_to_microsoft_authorize(): void
    {
        // Real provider (no facade mock) — also proves the SocialiteWasCalled wiring.
        $response = $this->get(route('microsoft.redirect'));

        $response->assertRedirect();
        $this->assertStringContainsString(
            'login.microsoftonline.com/test-tenant/oauth2/v2.0/authorize',
            $response->headers->get('Location'),
            'Authorize URL must target the configured tenant'
        );
    }

    public function test_callback_creates_role_less_user_for_new_email(): void
    {
        $this->mockMicrosoftUser('New.User@Dana.id');

        $this->get(route('microsoft.callback'))
            ->assertRedirect(route('filament.admin.auth.login'));

        $user = User::where('email', 'new.user@dana.id')->first();
        $this->assertNotNull($user, 'User should be JIT-created with a lowercased email');
        $this->assertNotNull($user->email_verified_at);
        $this->assertCount(0, $user->roles, 'New SSO user must have no role until an admin grants one');
        $this->assertGuest();
        $this->assertEquals(
            'Your account has been created — an admin must grant you access before you can sign in.',
            session('microsoft_error')
        );
    }

    public function test_callback_matches_existing_user_by_email_case_insensitively(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['email' => 'John.Doe@dana.id']);
        $user->assignRole('user');

        $this->mockMicrosoftUser('JOHN.DOE@DANA.ID', 'John Doe');

        $this->get(route('microsoft.callback'))
            ->assertRedirect(PanelHome::urlFor($user));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->fresh()->hasRole('user'), 'Existing role must be untouched');
        $this->assertEquals(1, User::count(), 'No duplicate row for a mixed-case email');
    }

    public function test_callback_locks_out_can_login_false_user(): void
    {
        $user = User::factory()->create(['email' => 'locked@dana.id', 'can_login' => false]);

        $this->mockMicrosoftUser('locked@dana.id');

        $this->get(route('microsoft.callback'))
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
        $this->assertEquals(
            'Your account does not have access to the portal. Please contact an admin.',
            session('microsoft_error')
        );
    }

    public function test_authenticated_user_cannot_reach_redirect_route(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)
            ->get('/auth/microsoft/redirect')
            ->assertRedirect(route('filament.admin.pages.dashboard'));
    }

    public function test_login_page_is_sso_first_with_collapsed_admin_form(): void
    {
        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSee('Sign in with Microsoft')
            ->assertSee(route('microsoft.redirect'), escape: false)
            ->assertSee('Technical risk, one place.')
            ->assertSee('Admin password login', escape: false)
            ->assertSee('Forgot password?', escape: false);
    }

    public function test_password_login_rejects_non_admin_users(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['password' => bcrypt('secret123')]);
        $user->assignRole('user');

        Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'secret123')
            ->call('authenticate')
            ->assertHasErrors(['data.email']);

        $this->assertGuest();
    }

    public function test_password_login_rejects_role_less_sso_users(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'secret123')
            ->call('authenticate')
            ->assertHasErrors(['data.email']);

        $this->assertGuest();
    }

    public function test_password_login_allows_admins(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['password' => bcrypt('secret123')]);
        $admin->assignRole('admin');

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'secret123')
            ->call('authenticate')
            ->assertRedirect();

        $this->assertAuthenticatedAs($admin);
        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }
}
