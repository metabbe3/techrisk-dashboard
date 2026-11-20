<?php

namespace Tests\Feature;

use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\ActionImprovementsRelationManager;
use App\Mail\GroupNotificationMail;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request 2026-10-13: every reminder email sent for an action
 * improvement is recorded on the row (last_reminded_at + reminders_sent),
 * and reminder_frequency finally throttles the daily overdue batch.
 */
class ActionImprovementReminderTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $pic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pic = User::factory()->create();
    }

    private function overdueAction(array $attrs = []): ActionImprovement
    {
        $incident = Incident::factory()->create();

        return ActionImprovement::factory()->create(array_merge([
            'incident_id' => $incident->id,
            'pic_email' => [$this->pic->email],
            'reminder' => true,
            'status' => 'pending',
            'due_date' => now()->subDays(3)->startOfDay(),
        ], $attrs));
    }

    private function inAppRows(string $type = 'action_improvement_%'): int
    {
        return DB::table('notifications')
            ->where('notifiable_id', $this->pic->id)
            ->where('data', 'like', '%"type":"'.$type.'"%')
            ->count();
    }

    public function test_command_records_each_sent_email(): void
    {
        $this->overdueAction();
        ActionImprovement::factory()->create([
            'incident_id' => Incident::factory()->create()->id,
            'pic_email' => [$this->pic->email],
            'reminder' => true,
            'status' => 'pending',
            'due_date' => now()->addDays(7)->startOfDay(),
        ]);

        $this->artisan('reminders:send-action-improvements')->assertSuccessful();

        $actions = ActionImprovement::query()->get();
        $this->assertCount(2, $actions);
        foreach ($actions as $action) {
            $this->assertSame(1, $action->reminders_sent);
            $this->assertNotNull($action->last_reminded_at);
        }
    }

    public function test_weekly_and_biweekly_frequency_throttle_the_overdue_batch(): void
    {
        // Weekly, reminded yesterday → skipped today.
        $this->overdueAction([
            'reminder_frequency' => 'weekly',
            'last_reminded_at' => now()->subDay(),
            'reminders_sent' => 1,
        ]);
        // Weekly, reminded 8 days ago → due again.
        $dueAgain = $this->overdueAction([
            'reminder_frequency' => 'weekly',
            'last_reminded_at' => now()->subDays(8),
            'reminders_sent' => 1,
        ]);
        // Biweekly, reminded 10 days ago → still inside the 14-day window.
        $this->overdueAction([
            'reminder_frequency' => 'biweekly',
            'last_reminded_at' => now()->subDays(10),
            'reminders_sent' => 1,
        ]);
        // No frequency → daily, reminded yesterday → still sent.
        $this->overdueAction([
            'reminder_frequency' => null,
            'last_reminded_at' => now()->subDay(),
            'reminders_sent' => 1,
        ]);

        $this->artisan('reminders:send-action-improvements')->assertSuccessful();

        $this->assertSame(2, $dueAgain->refresh()->reminders_sent);
        $this->assertSame(
            [1, 2, 1, 2],
            ActionImprovement::query()->orderBy('id')->pluck('reminders_sent')->all(),
            'skipped: weekly-reminded-yesterday, biweekly-inside-window; sent: weekly-8d-old and frequency-less'
        );
        // Skipped rows get no in-app notification either — the throttle is on
        // the whole send, not just the email half.
        $this->assertSame(2, $this->inAppRows('action_improvement_overdue'));
    }

    public function test_no_record_when_every_recipient_has_mail_disabled(): void
    {
        NotificationPreference::create([
            'user_id' => $this->pic->id,
            'email_action_improvement_overdue' => false,
        ]);
        $action = $this->overdueAction();

        Mail::fake();
        $this->artisan('reminders:send-action-improvements')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(0, $action->refresh()->reminders_sent);
        $this->assertNull($action->refresh()->last_reminded_at);
        // The in-app half still lands (database preference is separate).
        $this->assertSame(1, $this->inAppRows('action_improvement_overdue'));
    }

    public function test_email_button_records_the_send_and_hides_on_done(): void
    {
        Mail::fake();
        $pending = $this->overdueAction();

        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $pending->incident,
            'pageClass' => EditIncident::class,
        ])
            ->assertSuccessful()
            ->callTableAction('send_reminder', $pending);

        Mail::assertSent(GroupNotificationMail::class, 1);
        $this->assertSame(1, $pending->refresh()->reminders_sent);
        $this->assertNotNull($pending->refresh()->last_reminded_at);

        // Done rows never show the Email button.
        $pending->update(['status' => 'done']);
        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $pending->incident,
            'pageClass' => EditIncident::class,
        ])
            ->assertSuccessful()
            ->assertTableActionHidden('send_reminder', $pending);
    }
}
