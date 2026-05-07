<?php

namespace Tests\Feature;

use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationScheduledCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pic;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->pic = User::factory()->create();
        $this->pic->assignRole('user');
    }

    private function notificationsFor(User $user, string $type): int
    {
        return DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('notifiable_type', User::class)
            ->where('data', 'like', '%"type":"' . $type . '"%')
            ->count();
    }

    public function test_reminder_command_sends_due_soon_notification(): void
    {
        $incident = Incident::factory()->create(['pic_id' => $this->pic->id]);

        ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => [$this->pic->email],
            'reminder' => true,
            'status' => 'pending',
            'due_date' => now()->addDays(7)->startOfDay(),
        ]);

        $this->artisan('reminders:send-action-improvements')
            ->assertSuccessful();

        $this->assertEquals(1, $this->notificationsFor($this->pic, 'action_improvement_due_soon'));
    }

    public function test_reminder_command_sends_overdue_notification(): void
    {
        $incident = Incident::factory()->create(['pic_id' => $this->pic->id]);

        ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => [$this->pic->email],
            'reminder' => true,
            'status' => 'pending',
            'due_date' => now()->subDays(3)->startOfDay(),
        ]);

        $this->artisan('reminders:send-action-improvements')
            ->assertSuccessful();

        $this->assertEquals(1, $this->notificationsFor($this->pic, 'action_improvement_overdue'));
    }

    public function test_reminder_command_escalates_7_day_overdue_to_admin(): void
    {
        $incident = Incident::factory()->create(['pic_id' => $this->pic->id]);

        ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => [$this->pic->email],
            'reminder' => true,
            'status' => 'pending',
            'due_date' => now()->subDays(10)->startOfDay(),
        ]);

        $this->artisan('reminders:send-action-improvements')
            ->assertSuccessful();

        // PIC gets overdue
        $this->assertEquals(1, $this->notificationsFor($this->pic, 'action_improvement_overdue'));

        // Admin gets escalation
        $this->assertEquals(1, $this->notificationsFor($this->admin, 'action_improvement_escalated'));
    }

    public function test_reminder_command_skips_completed_items(): void
    {
        $incident = Incident::factory()->create(['pic_id' => $this->pic->id]);

        ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => [$this->pic->email],
            'reminder' => true,
            'status' => 'completed',
            'due_date' => now()->subDays(3)->startOfDay(),
        ]);

        $this->artisan('reminders:send-action-improvements')
            ->assertSuccessful();

        $this->assertEquals(0, $this->notificationsFor($this->pic, 'action_improvement_overdue'));
    }

    public function test_weekly_digest_sends_to_admins_only(): void
    {
        $incident = Incident::factory()->create(['pic_id' => $this->pic->id]);

        ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => [$this->pic->email],
            'status' => 'pending',
            'due_date' => now()->subDays(3)->startOfDay(),
        ]);

        $this->artisan('reminders:send-weekly-overdue-digest')
            ->assertSuccessful();

        $this->assertEquals(1, $this->notificationsFor($this->admin, 'weekly_overdue_digest'));
        $this->assertEquals(0, $this->notificationsFor($this->pic, 'weekly_overdue_digest'));
    }

    public function test_weekly_digest_skips_when_no_overdue_items(): void
    {
        $this->artisan('reminders:send-weekly-overdue-digest')
            ->assertSuccessful();

        $this->assertEquals(0, $this->notificationsFor($this->admin, 'weekly_overdue_digest'));
    }
}
