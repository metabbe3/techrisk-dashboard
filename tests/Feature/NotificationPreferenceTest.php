<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
        Permission::firstOrCreate(['name' => 'access dashboard']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo('access dashboard');
    }

    public function test_notification_preferences_are_created_with_defaults(): void
    {
        $prefs = NotificationPreference::forUser($this->user);

        $this->assertNotNull($prefs->email_incident_assignment);
        $this->assertNotNull($prefs->database_incident_assignment);
    }

    public function test_notification_is_sent_when_all_channels_enabled(): void
    {
        Notification::fake();

        $incident = Incident::factory()->create(['pic_id' => $this->user->id]);

        $this->user->notify(new \App\Notifications\AssignedAsPicNotification($incident));

        Notification::assertSentTo(
            $this->user,
            \App\Notifications\ChannelFilteredNotification::class
        );
    }

    public function test_notification_is_dropped_when_all_channels_disabled(): void
    {
        Notification::fake();

        $prefs = NotificationPreference::forUser($this->user);
        $prefs->update([
            'email_incident_assignment' => false,
            'database_incident_assignment' => false,
        ]);

        $incident = Incident::factory()->create(['pic_id' => $this->user->id]);

        $this->user->notify(new \App\Notifications\AssignedAsPicNotification($incident));

        Notification::assertNothingSent();
    }

    public function test_notification_center_page_is_accessible(): void
    {
        $this->user->assignRole('admin');

        $response = $this->actingAs($this->user)
            ->get('/admin/notification-center');

        $response->assertSuccessful();
    }

    public function test_notification_preference_resource_is_accessible(): void
    {
        $this->user->assignRole('admin');

        $response = $this->actingAs($this->user)
            ->get('/admin/notification-preferences');

        $response->assertSuccessful();
    }
}
