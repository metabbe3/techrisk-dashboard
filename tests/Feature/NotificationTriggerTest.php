<?php

namespace Tests\Feature;

use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationTriggerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pic;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
        Permission::firstOrCreate(['name' => 'access dashboard']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->givePermissionTo('access dashboard');

        $this->pic = User::factory()->create();
        $this->pic->assignRole('user');
        $this->pic->givePermissionTo('access dashboard');
    }

    private function notificationsFor(User $user, string $type): int
    {
        return DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('notifiable_type', User::class)
            ->where('data', 'like', '%"type":"'.$type.'"%')
            ->count();
    }

    public function test_pic_gets_assigned_notification_on_incident_creation(): void
    {
        $incident = Incident::factory()->create();
        $incident->pics()->attach($this->pic->id);

        $this->assertEquals(1, $this->notificationsFor($this->pic, 'incident_assignment'));
    }

    public function test_admin_gets_critical_incident_notification_for_p1(): void
    {
        tap(Incident::factory()->create(['severity' => 'P1']), fn ($i) => $i->pics()->attach($this->pic->id));

        $this->assertEquals(1, $this->notificationsFor($this->admin, 'critical_incident'));
    }

    public function test_admin_gets_critical_incident_notification_for_p2(): void
    {
        tap(Incident::factory()->create(['severity' => 'P2']), fn ($i) => $i->pics()->attach($this->pic->id));

        $this->assertEquals(1, $this->notificationsFor($this->admin, 'critical_incident'));
    }

    public function test_admin_does_not_get_critical_notification_for_p3(): void
    {
        tap(Incident::factory()->create(['severity' => 'P3']), fn ($i) => $i->pics()->attach($this->pic->id));

        $this->assertEquals(0, $this->notificationsFor($this->admin, 'critical_incident'));
    }

    public function test_admin_gets_pic_assigned_notification(): void
    {
        tap(Incident::factory()->create(), fn ($i) => $i->pics()->attach($this->pic->id));

        $this->assertEquals(1, $this->notificationsFor($this->admin, 'pic_assigned'));
    }

    public function test_pic_does_not_get_pic_assigned_notification_about_themselves(): void
    {
        tap(Incident::factory()->create(), fn ($i) => $i->pics()->attach($this->pic->id));

        $this->assertEquals(0, $this->notificationsFor($this->pic, 'pic_assigned'));
    }

    public function test_incident_status_change_notifies_pic(): void
    {
        $this->actingAs($this->admin);

        $incident = Incident::factory()->create(['incident_status' => 'Open']);
        $incident->pics()->attach($this->pic->id);

        $incident->update(['incident_status' => 'In Progress']);

        $this->assertEquals(1, $this->notificationsFor($this->pic, 'incident_status_changed'));
    }

    public function test_pic_updating_own_incident_does_not_notify_themselves(): void
    {
        $this->actingAs($this->pic);

        $incident = Incident::factory()->create(['incident_status' => 'Open']);
        $incident->pics()->attach($this->pic->id);

        $incident->update(['incident_status' => 'In Progress']);

        $this->assertEquals(0, $this->notificationsFor($this->pic, 'incident_status_changed'));
    }

    public function test_pic_change_sends_assignment_to_new_pic(): void
    {
        $newPic = User::factory()->create();
        $newPic->givePermissionTo('access dashboard');

        $this->actingAs($this->admin);

        $incident = Incident::factory()->create();
        $incident->pics()->attach($this->pic->id);

        $incident->pics()->sync([$newPic->id]);

        $this->assertEquals(1, $this->notificationsFor($newPic, 'incident_assignment'));
        // Actor-exclusion contract: the admin is the current user on both the
        // attach and sync paths — the pivot hook excludes them every time.
        $this->assertEquals(0, $this->notificationsFor($this->admin, 'pic_assigned'));

        // Re-syncing an unchanged PIC list must not re-email anyone.
        $incident->pics()->sync([$newPic->id]);
        $this->assertEquals(1, $this->notificationsFor($newPic, 'incident_assignment'));
    }

    public function test_action_improvement_creation_notifies_pic_user(): void
    {
        $incident = Incident::factory()->create();
        $incident->pics()->attach($this->pic->id);

        ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => [$this->pic->email],
        ]);

        $this->assertEquals(1, $this->notificationsFor($this->pic, 'action_improvement_assigned'));
    }

    public function test_action_improvement_skips_non_user_emails(): void
    {
        $incident = Incident::factory()->create();
        $incident->pics()->attach($this->pic->id);

        ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => ['nonexistent@example.com'],
        ]);

        // No exception, no crash — silently skipped
        $this->expectNotToPerformAssertions();
    }
}
