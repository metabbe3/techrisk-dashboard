<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Jobs\SyncMicrosoftDirectoryJob;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Users-page "Sync from Entra" button + the queued job behind it: the job
 * runs the sync command and reports the result through a database
 * notification (sidebar bell).
 */
class SyncDirectoryButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config()->set('services.microsoft', [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost:8000/auth/microsoft/callback',
            'tenant' => 'test-tenant',
        ]);
    }

    public function test_button_dispatches_the_job_for_managers(): void
    {
        $manager = User::factory()->create()->givePermissionTo('view users', 'manage users');

        Queue::fake();

        Livewire::actingAs($manager)
            ->test(ListUsers::class)
            ->callAction('syncDirectory');

        Queue::assertPushed(SyncMicrosoftDirectoryJob::class, fn (SyncMicrosoftDirectoryJob $job) => $job->userId === $manager->id);
    }

    public function test_button_hidden_without_manage_users(): void
    {
        $viewer = User::factory()->create()->givePermissionTo('view users');

        Livewire::actingAs($viewer)
            ->test(ListUsers::class)
            ->assertSuccessful()
            ->assertDontSee('Sync from Entra');
    }

    public function test_job_runs_sync_and_notifies_the_triggering_user(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token']),
            'graph.microsoft.com/*' => Http::response([
                'value' => [
                    ['displayName' => 'Budi Santoso', 'mail' => 'budi@dana.id', 'userPrincipalName' => 'budi@dana.id', 'accountEnabled' => true],
                ],
            ]),
        ]);

        $admin = User::factory()->create()->givePermissionTo('manage users');

        (new SyncMicrosoftDirectoryJob($admin->id))->handle();

        $this->assertNotNull(User::where('email', 'budi@dana.id')->first(), 'Sync ran inside the job');
        $this->assertSame(1, $admin->notifications()->count(), 'Completion landed as a database notification');
        $this->assertStringContainsString('Directory sync completed', (string) json_encode($admin->notifications()->first()->data));
    }
}
