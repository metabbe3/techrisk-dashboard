<?php

namespace Tests\Feature\Notion;

use App\Filament\Pages\SystemSettings;
use App\Models\User;
use App\Services\Notion\NotionMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The OAuth routes store the workspace-wide Notion connection — they must be
 * gated exactly like the Notion Settings page (`manage api tokens`), or any
 * logged-in user could repoint the connection at their own Notion account.
 */
class NotionOauthRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('mcp.oauth.notion.connect'))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_user_without_permission_gets_403_on_connect(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('mcp.oauth.notion.connect'))
            ->assertForbidden();
    }

    public function test_user_without_permission_gets_403_on_callback(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('mcp.oauth.notion.callback'))
            ->assertForbidden();
    }

    public function test_admin_passes_the_gate(): void
    {
        Permission::firstOrCreate(['name' => 'manage api tokens']);

        $admin = User::factory()->create();
        $admin->givePermissionTo('manage api tokens');

        // The gate is the subject under test — after it passes, the connect
        // route performs OAuth discovery against Notion's server, which is
        // not reachable in tests. Fake every HTTP call so the flow fails
        // fast with a non-403 status.
        Http::fake(['*' => Http::response('', 500)]);

        $response = $this->actingAs($admin)->get(route('mcp.oauth.notion.connect'));

        $this->assertNotSame(403, $response->status());
    }

    public function test_successful_callback_stores_tokens_and_redirects_to_the_settings_page(): void
    {
        Permission::firstOrCreate(['name' => 'manage api tokens']);

        $admin = User::factory()->create();
        $admin->givePermissionTo('manage api tokens');

        // Seed the pending-authorization session state the package's
        // callback exchange expects — as if this browser just came back
        // from Notion's consent screen.
        $state = 'oauth-state-123';
        Session::put('mcp.oauth.'.sha1((string) config('notion.mcp_url')), [
            'state' => $state,
            'verifier' => 'test-verifier',
            'client_id' => 'client-id',
            'token_endpoint' => 'https://mcp.notion.com/token',
            'token_auth_method' => 'none',
            'redirect_uri' => route('mcp.oauth.notion.callback'),
            'return_to' => null,
            'issuer' => 'https://mcp.notion.com',
            'iss_supported' => false,
        ]);

        Http::fake(['*' => Http::response([
            'access_token' => 'at-secret',
            'refresh_token' => 'rt-secret',
            'expires_in' => 3600,
        ])]);

        $response = $this->actingAs($admin)
            ->get(route('mcp.oauth.notion.callback', ['code' => 'auth-code', 'state' => $state]));

        $response->assertRedirect(SystemSettings::getUrl(['tab' => 'notion']));
        $this->assertTrue(app(NotionMcpClient::class)->connected());
    }

    public function test_denied_oauth_callback_redirects_to_settings_with_a_notice(): void
    {
        Permission::firstOrCreate(['name' => 'manage api tokens']);

        $admin = User::factory()->create();
        $admin->givePermissionTo('manage api tokens');

        // Admin cancelled on Notion's consent screen: the redirect back
        // carries ?error=access_denied. The vendor throws before our
        // handler runs — that must land as a notice, not a raw 500.
        $response = $this->actingAs($admin)
            ->get(route('mcp.oauth.notion.callback', ['error' => 'access_denied']));

        $response->assertRedirect(SystemSettings::getUrl(['tab' => 'notion']));
        $this->assertStringContainsString(
            'access_denied',
            (string) collect(session('filament.notifications', []))->pluck('body')->implode(' '),
        );
    }

    public function test_missing_oauth_session_redirects_to_settings(): void
    {
        Permission::firstOrCreate(['name' => 'manage api tokens']);

        $admin = User::factory()->create();
        $admin->givePermissionTo('manage api tokens');

        // Session expired between Connect and the callback — no pending
        // authorization in the session. Same rule: notice, not a 500.
        $response = $this->actingAs($admin)
            ->get(route('mcp.oauth.notion.callback', ['code' => 'auth-code', 'state' => 'whatever']));

        $response->assertRedirect(SystemSettings::getUrl(['tab' => 'notion']));
    }
}
