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

        // Pivot attach notifies admins inline; without this the reverb
        // broadcast failure masked the command-level RelationNotFoundException
        // that reminders:send-action-improvements threw on any due action.
        config(['broadcasting.default' => 'log']);

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
            ->where('data', 'like', '%"type":"'.$type.'"%')
            ->count();
    }

    public function test_reminder_command_sends_due_soon_notification(): void
    {
        $incident = Incident::factory()->create();
        $incident->pics()->attach($this->pic->id);

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
        $incident = Incident::factory()->create();
        $incident->pics()->attach($this->pic->id);

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

    public function test_overdue_7_day_still_notifies_only_pics_never_admins(): void
    {
        $incident = Incident::factory()->create();
        $incident->pics()->attach($this->pic->id);

        ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => [$this->pic->email],
            'reminder' => true,
            'status' => 'pending',
            // 30 days overdue — past the old 7-day escalation threshold.
            'due_date' => now()->subDays(30)->startOfDay(),
        ]);

        $this->artisan('reminders:send-action-improvements')
            ->assertSuccessful();

        // PIC still gets the overdue reminder
        $this->assertEquals(1, $this->notificationsFor($this->pic, 'action_improvement_overdue'));

        // Admins never receive action-improvement emails (owner rule 2026-10-09)
        $this->assertEquals(0, $this->notificationsFor($this->admin, 'action_improvement_escalated'));
        $this->assertEquals(0, $this->notificationsFor($this->admin, 'action_improvement_overdue'));
    }

    public function test_reminder_command_skips_completed_items(): void
    {
        $incident = Incident::factory()->create();
        $incident->pics()->attach($this->pic->id);

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
}
