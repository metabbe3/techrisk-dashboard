<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\AccessRequest;
use App\Models\User;
use App\Models\UserAuditLogSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

/**
 * Red-team audit 2026-11-21 — FIX pins for W5/W6/W8 (the W1–W4 pins live in
 * RedTeamPoCTest). Each pin fails if its fix regresses.
 */
class RedTeamFixPinsTest extends TestCase
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

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Mock Entra with an INDEPENDENT object id (not derived from email). */
    private function mockEntraUser(string $email, string $azureId): void
    {
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn(new class($email, $azureId)
        {
            public function __construct(private string $email, private string $azureId) {}

            public function user(): object
            {
                return new class($this->email, $this->azureId)
                {
                    public function __construct(private string $email, private string $azureId) {}

                    public function getEmail(): string
                    {
                        return $this->email;
                    }

                    public function getName(): string
                    {
                        return 'Entra User';
                    }

                    public function getId(): string
                    {
                        return $this->azureId;
                    }
                };
            }
        });
    }

    // ---------------------------------------------------------------
    // W5 — Entra object-id identity binding
    // ---------------------------------------------------------------

    public function test_w5_azure_id_match_wins_over_email(): void
    {
        // User A owns azure-id AAAA with email OLD@x.id; Entra now returns
        // AAAA with a DIFFERENT email. The azure_id match must win — A logs
        // in, B (owner of the new email) is untouched.
        $userA = User::factory()->create(['email' => 'old@x.id', 'azure_id' => 'AAAA']);
        $userB = User::factory()->create(['email' => 'new@x.id', 'azure_id' => null]);

        $this->mockEntraUser('new@x.id', 'AAAA');

        $this->get(route('microsoft.callback'))->assertRedirect();

        $this->assertAuthenticatedAs($userA->fresh());
        $this->assertSame('old@x.id', $userA->fresh()->email);
    }

    public function test_w5_email_fallback_stamps_the_azure_id(): void
    {
        $user = User::factory()->create(['email' => 'legacy@x.id', 'azure_id' => null]);

        $this->mockEntraUser('legacy@x.id', 'BBBB');

        $this->get(route('microsoft.callback'))->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('BBBB', $user->fresh()->azure_id);
    }

    public function test_w5_jit_created_user_carries_the_azure_id(): void
    {
        $this->mockEntraUser('fresh@x.id', 'CCCC');

        $this->get(route('microsoft.callback'))->assertRedirect();

        $user = User::where('email', 'fresh@x.id')->first();
        $this->assertNotNull($user);
        $this->assertSame('CCCC', $user->azure_id);
        $this->assertTrue($user->fresh()->hasRole('team-viewer'));
    }

    // ---------------------------------------------------------------
    // W6 — access-request approval must not rewrite existing users' years
    // ---------------------------------------------------------------

    public function test_w6_approving_a_request_from_an_existing_user_keeps_their_years(): void
    {
        $existing = User::factory()->create(['email' => 'member@x.id']);
        UserAuditLogSetting::create([
            'user_id' => $existing->id,
            'allowed_years' => [2024],
            'can_view_all_logs' => false,
        ]);

        $request = AccessRequest::create([
            'id' => 'req-w6-existing',
            'name' => 'Member',
            'email' => 'member@x.id',
            'password' => null,
            'requested_duration_days' => 30,
            'requested_years' => [2026],
            'reason' => 'please',
            'status' => 'pending',
        ]);

        $request->approve(1);

        $setting = UserAuditLogSetting::where('user_id', $existing->id)->first();
        $this->assertSame([2024], $setting->allowed_years, 'existing users keep their audit-log years');
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_w6_approving_a_new_user_sets_the_requested_years(): void
    {
        $request = AccessRequest::create([
            'id' => 'req-w6-new',
            'name' => 'Newcomer',
            'email' => 'newcomer@x.id',
            'password' => Hash::make('secret123'),
            'requested_duration_days' => 30,
            'requested_years' => [2026],
            'reason' => 'please',
            'status' => 'pending',
        ]);

        $user = $request->approve(1);

        $setting = UserAuditLogSetting::where('user_id', $user->id)->first();
        $this->assertNotNull($setting);
        $this->assertSame([2026], $setting->allowed_years);
    }

    // ---------------------------------------------------------------
    // W8 — API docs are not anonymous; token plaintext rides encrypted
    // ---------------------------------------------------------------

    public function test_w8_docs_routes_are_not_anonymous(): void
    {
        $this->get('/docs.openapi')->assertRedirect();
        $this->get('/docs.postman')->assertRedirect();

        // And an authenticated user gets past the gate (404 = file missing).
        $user = User::factory()->create();
        $this->actingAs($user)->get('/docs.openapi')->assertNotFound();
    }

    public function test_w8_token_create_stores_the_plaintext_encrypted_in_session(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->givePermissionTo(['manage api tokens', 'access api']);

        $this->actingAs($admin);

        $page = \Livewire\Livewire::test(\App\Filament\Resources\ApiTokenResource\Pages\CreateApiToken::class)
            ->set('data.name', 'pin-token')
            ->set('data.tokenable_id', $admin->id)
            ->set('data.abilities', ['*'])
            ->set('data.allowed_endpoints', ['*'])
            ->call('create');

        $tokenId = $page->instance()->record->id;
        $sessionValue = session('api_token_plain_'.$tokenId);

        $this->assertNotNull($sessionValue);
        $this->assertStringStartsWith('eyJ', (string) $sessionValue, 'session value must be ciphertext');
        // Round-trips to a Sanctum plaintext (`id|` + alphanumeric token).
        $plain = Crypt::decryptString($sessionValue);
        $this->assertMatchesRegularExpression('/^\d+\|[A-Za-z0-9]{40,}$/', $plain);
    }
}
