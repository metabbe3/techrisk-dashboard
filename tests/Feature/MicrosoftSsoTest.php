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
 * Microsoft (Entra ID) SSO: JIT-creates users on first login — ANY tenant
 * identity may sign in (owner 2026-11-20: role-less sessions get the
 * restricted team-viewer role and land on their incident slice; the panel
 * is still denied to can_login=false directory rows). Email-matched existing
 * users keep their current access untouched.
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

        // The JIT path assignRole('team-viewer') — the role must exist.
        $this->seed(RolesAndPermissionsSeeder::class);
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

    public function test_callback_jit_assigns_team_viewer_and_lands_on_the_incidents_list(): void
    {
        $this->mockMicrosoftUser('New.User@Dana.id');

        // Deliberate behavior change (owner 2026-11-20): a fresh SSO identity
        // gets team-viewer — their incident slice, not the sso-signed-in page.
        $this->get(route('microsoft.callback'))
            ->assertRedirect(route('filament.admin.resources.incidents.index'));

        $user = User::where('email', 'new.user@dana.id')->first();
        $this->assertNotNull($user, 'User should be JIT-created with a lowercased email');
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->fresh()->hasRole('team-viewer'), 'JIT must assign the restricted role');
        // Revocation survival is pinned by test_can_login_false_user_... — the
        // callback itself never writes can_login (it only defaults true on
        // fresh rows via the column).
        $this->assertAuthenticatedAs($user);
    }

    public function test_callback_sends_role_less_user_back_to_the_intended_form_url(): void
    {
        $this->mockMicrosoftUser('Peer@Dana.id', 'Forwarded Peer');

        $intended = '/investigation/abc123';
        session(['url.intended' => $intended]);

        $this->get(route('microsoft.callback'))
            ->assertRedirect($intended);

        $this->assertAuthenticatedAs(User::where('email', 'peer@dana.id')->first());
    }

    public function test_callback_sends_panel_user_to_the_intended_url_over_panel_home(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->mockMicrosoftUser($admin->email, 'Admin');
        session(['url.intended' => '/investigation/abc123']);

        $this->get(route('microsoft.callback'))
            ->assertRedirect('/investigation/abc123');
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

    public function test_can_login_false_user_signs_in_but_never_reaches_the_panel(): void
    {
        $user = User::factory()->create(['email' => 'locked@dana.id', 'can_login' => false]);

        $this->mockMicrosoftUser('locked@dana.id');

        // Directory-synced contacts (can_login=false) can now fill a forwarded
        // investigation form — but the panel gate still denies them (the
        // JIT team-viewer assignment never fires for them either).
        $this->get(route('microsoft.callback'))
            ->assertRedirect(route('sso-signed-in'));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertFalse($user->fresh()->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')));
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
