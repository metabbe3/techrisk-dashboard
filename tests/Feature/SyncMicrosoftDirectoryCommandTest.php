<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * users:sync-directory — app-only Graph pull of the org directory (name,
 * email, job title, team, org-chart manager), bulk-upserted through the ONE
 * directory-sync home (User::syncDirectoryRows): new rows login-disabled,
 * existing users keep name/password/login/roles — the sync only owns
 * job_title/department/manager_id.
 */
class SyncMicrosoftDirectoryCommandTest extends TestCase
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

    public function test_pulls_directory_and_upserts_login_disabled_users(): void
    {
        $this->fakeGraph([
            'value' => [
                [
                    'displayName' => 'Budi Santoso', 'mail' => 'Budi.Santoso@dana.id',
                    'jobTitle' => 'Backend Engineer', 'department' => 'Platform',
                    'manager' => ['mail' => 'NoMail@dana.id'], 'userPrincipalName' => 'budi@corp', 'accountEnabled' => true,
                ],
                [
                    'displayName' => 'No Mail <Person>', 'mail' => null, 'userPrincipalName' => 'nomail@dana.id',
                    'jobTitle' => 'Engineering Manager', 'department' => 'Platform',
                    'manager' => ['userPrincipalName' => 'Up.Ladder@dana.id'], 'accountEnabled' => true,
                ],
                ['displayName' => 'No Email At All', 'mail' => null, 'userPrincipalName' => 'broken_upn', 'accountEnabled' => true],
            ],
        ]);

        $this->artisan('users:sync-directory')->assertSuccessful();

        $budi = User::where('email', 'budi.santoso@dana.id')->first();
        $this->assertNotNull($budi, 'Mail address wins over UPN, lowercased');
        $this->assertSame('Budi Santoso', $budi->name);
        $this->assertFalse($budi->can_login, 'Directory rows are login-disabled');
        $this->assertSame('Backend Engineer', $budi->job_title);
        $this->assertSame('Platform', $budi->department);

        $nomail = User::where('email', 'nomail@dana.id')->first();
        $this->assertNotNull($nomail, 'UPN falls back when mail is null');
        $this->assertSame('No Mail Person', $nomail->name, 'Angle brackets stripped from the name');
        $this->assertSame('Engineering Manager', $nomail->job_title);

        // Org chart: Budi → nomail, nomail → up.ladder; unresolvable manager skipped.
        $this->assertSame($nomail->id, $budi->manager_id, 'Direct manager linked by email');
        $upLadder = User::where('email', 'up.ladder@dana.id')->first();
        $this->assertNull($upLadder, 'Unresolvable manager email creates nothing');
        $this->assertNull($nomail->manager_id);

        $this->assertSame(2, User::count());
        $this->assertEmpty($nomail->roles, 'No role — access only via admin grant');
    }

    public function test_follows_odata_pagination(): void
    {
        $this->fakeGraph(
            [
                'value' => [
                    ['displayName' => 'Page One', 'mail' => 'page1@dana.id', 'userPrincipalName' => 'page1@dana.id', 'accountEnabled' => true],
                ],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users?$skiptoken=PAGE2',
            ],
            [
                'value' => [
                    ['displayName' => 'Page Two', 'mail' => 'page2@dana.id', 'userPrincipalName' => 'page2@dana.id', 'accountEnabled' => true],
                ],
            ],
        );

        $this->artisan('users:sync-directory')->assertSuccessful();

        $this->assertSame(2, User::count());
        $this->assertDatabaseHas('users', ['email' => 'page1@dana.id', 'name' => 'Page One']);
        $this->assertDatabaseHas('users', ['email' => 'page2@dana.id', 'name' => 'Page Two']);
    }

    public function test_existing_users_keep_identity_but_get_directory_fields_refreshed(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create([
            'email' => 'admin@dana.id', 'name' => 'Portal Admin', 'can_login' => true, 'password' => 'kept-secret',
        ]);
        $admin->assignRole('admin');

        $this->fakeGraph([
            'value' => [
                [
                    'displayName' => 'Directory Name', 'mail' => 'admin@dana.id', 'userPrincipalName' => 'admin@dana.id',
                    'jobTitle' => 'Director', 'department' => 'Risk', 'manager' => null, 'accountEnabled' => true,
                ],
            ],
        ]);

        $this->artisan('users:sync-directory')->assertSuccessful();

        $admin = $admin->fresh();
        $this->assertSame('Portal Admin', $admin->name, 'Sync never overwrites the stored name');
        $this->assertTrue($admin->can_login);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue(password_verify('kept-secret', $admin->password), 'Password never touched');
        $this->assertSame('Director', $admin->job_title, 'Sync owns job_title/department on existing rows');
        $this->assertSame('Risk', $admin->department);
        $this->assertSame(1, User::count(), 'Same email — no duplicate row');
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->fakeGraph([
            'value' => [
                ['displayName' => 'Budi', 'mail' => 'budi@dana.id', 'userPrincipalName' => 'budi@dana.id', 'accountEnabled' => true],
            ],
        ]);

        $this->artisan('users:sync-directory', ['--dry-run' => true])
            ->expectsOutputToContain('1 directory entries found')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_missing_config_fails_before_any_http_call(): void
    {
        config()->set('services.microsoft.tenant', null);

        Http::fake();

        $this->artisan('users:sync-directory')->assertFailed();

        Http::assertNothingSent();
    }

    /**
     * ONE fake per URL family with a counter closure — two Http::fake stubs on
     * the same URL MERGE and the first stub keeps winning (pinned trap).
     */
    private function fakeGraph(array ...$pages): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token']),
            'graph.microsoft.com/*' => function () use (&$pages) {
                $page = array_shift($pages) ?? ['value' => []];

                return Http::response($page);
            },
        ]);
    }
}
