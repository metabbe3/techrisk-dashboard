<?php

namespace Tests\Feature\Mail;

use App\Filament\Resources\IncidentResource;
use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\ActionImprovementsRelationManager;
use App\Mail\InvestigationFormRequestMail;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\InvestigationFormRequest;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ActionImprovementOverdue;
use App\Notifications\IncidentNotDoneReminder;
use App\Notifications\StatusUpdateStaleReminder;
use App\Support\MailSettings;
use App\Support\ReminderMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One DANA-blue layout for every portal email (emails/notification.blade.php —
 * white card, indigo #108EE9 band/button) and a REAL global kill-switch:
 * netcore_enabled gates every send at the MessageSending event (not just the
 * Netcore transport), and senders never consume a suppressed email's side
 * effects (reminder stamps, token rotation, escalation rungs).
 */
class EmailLayoutAndKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'log']);
    }

    /** The array-transport mailer — a REAL send records here; suppression does not. */
    private function arrayTransport(): ArrayTransport
    {
        config(['mail.default' => 'array']);
        app(MailManager::class)->purge('array');

        return app(MailManager::class)->mailer('array')->getSymfonyTransport();
    }

    private function incident(): Incident
    {
        return Incident::factory()->create([
            'no' => 'INC-TEST-1',
            'title' => 'Database timeout',
            'classification' => 'Incident',
        ]);
    }

    // ------------------------------------------------------------------
    // Layout
    // ------------------------------------------------------------------

    public function test_branded_layout_markers_on_all_surfaces(): void
    {
        $user = User::factory()->create();
        $incident = $this->incident();
        $action = ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => [$user->email],
            'status' => 'pending',
            'due_date' => now()->addDays(3),
        ]);

        $surfaces = [
            'trigger notification' => (new IncidentNotDoneReminder($incident))->toMail($user)->render(),
            'status reminder' => (new StatusUpdateStaleReminder($incident))->toMail($user)->render(),
            'action overdue' => (new ActionImprovementOverdue($action, 5))->toMail($user)->render(),
            'investigation form' => (new InvestigationFormRequestMail('Form request', '## Please fill **this**'))->render(),
            'password reset' => view('emails.notification', [
                'headline' => 'Reset your password',
                'actionText' => 'Reset Password',
                'actionUrl' => 'https://example.test/reset',
            ])->render(),
        ];

        foreach ($surfaces as $name => $html) {
            $this->assertStringContainsString('#108EE9', $html, "{$name}: indigo band missing");
            $this->assertStringContainsString('TechRisk Portal', $html, "{$name}: brand label missing");
            $this->assertStringNotContainsString('**', $html, "{$name}: raw markdown leak");
        }
    }

    public function test_action_mail_has_deep_link_status_and_tbd(): void
    {
        $user = User::factory()->create();
        $incident = $this->incident();
        $action = ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => [$user->email],
            'status' => 'on_progress',
            'due_date' => null,
        ]);

        $html = (new ActionImprovementOverdue($action, 5))->toMail($user)->render();

        // Deep link lands on the Action Improvements tab.
        $tab = array_search(ActionImprovementsRelationManager::class, IncidentResource::getRelations(), true);
        $this->assertStringContainsString('activeRelationManager='.($tab !== false ? $tab : 0), $html);
        // Incident number + status label + TBD (no invented date).
        $this->assertStringContainsString('INC-TEST-1', $html);
        $this->assertStringContainsString('On progress', $html);
        $this->assertStringContainsString('TBD', $html);
        $this->assertStringNotContainsString('**', $html, 'raw markdown leak');
    }

    // ------------------------------------------------------------------
    // Kill-switch: the gate itself
    // ------------------------------------------------------------------

    public function test_message_sending_gate_suppresses_real_sends(): void
    {
        Setting::set('netcore_enabled', true);
        $transport = $this->arrayTransport();

        MailSettings::apply();
        Mail::mailer('array')->raw('hello', fn ($m) => $m->to('a@b.c')->subject('t'));

        $this->assertCount(1, $transport->messages(), 'switch ON: send must go through');

        Setting::set('netcore_enabled', false);

        Mail::mailer('array')->raw('hello', fn ($m) => $m->to('a@b.c')->subject('t'));

        $this->assertCount(1, $transport->messages(), 'switch OFF: the second send must be suppressed');
    }

    public function test_reminder_mail_reports_zero_and_stamps_nothing_when_suppressed(): void
    {
        $user = User::factory()->create();
        $incident = $this->incident();
        $action = ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => [$user->email],
            'status' => 'pending',
            'due_date' => now()->subDay(),
        ]);

        Setting::set('netcore_enabled', false);

        $mailed = ReminderMail::send([$user], new ActionImprovementOverdue($action, 1));
        $action->recordReminderSent($mailed);

        $this->assertSame(0, $mailed);
        $this->assertNull($action->refresh()->last_reminded_at);
        $this->assertSame(0, $action->refresh()->reminders_sent);
    }

    public function test_investigation_form_reminders_do_not_rotate_tokens_when_suppressed(): void
    {
        $request = InvestigationFormRequest::create([
            'incident_id' => $this->incident()->id,
            'team' => 'Payments',
            'status' => InvestigationFormRequest::STATUS_PENDING,
            'expires_at' => now()->addDays(14),
            'created_by' => User::factory()->create()->id,
        ]);
        $recipient = $request->recipients()->create([
            'email' => 'a@x.com',
            'token_hash' => 'hash-before',
            'emailed_at' => now()->subDays(8),
            'reminder_count' => 3,
        ]);
        $hashBefore = $recipient->token_hash;

        Setting::set('netcore_enabled', false);

        $this->artisan('investigation-forms:send-reminders')->assertSuccessful();

        $recipient->refresh();
        $this->assertSame($hashBefore, $recipient->token_hash, 'suppressed email must NOT kill the working link');
        $this->assertSame(3, $recipient->reminder_count);
    }

    public function test_escalation_does_not_advance_when_suppressed(): void
    {
        $pic = User::factory()->create();
        $incident = $this->incident();
        $action = ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => [$pic->email],
            'reminder' => true,
            'status' => 'pending',
            'due_date' => now()->subDays(30),
            'escalation_level' => 0,
        ]);

        Setting::set('netcore_enabled', false);

        $this->artisan('reminders:send-action-improvements')->assertSuccessful();

        $this->assertSame(0, $action->refresh()->escalation_level, 'a suppressed email must not burn an escalation rung');
        $this->assertNull($action->refresh()->last_reminded_at);
    }

    public function test_manual_reminder_button_stamps_nothing_when_suppressed(): void
    {
        $pic = User::factory()->create();
        $incident = $this->incident();
        $action = ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => [$pic->email],
            'status' => 'pending',
            'due_date' => now()->subDay(),
        ]);

        Setting::set('netcore_enabled', false);

        Livewire::actingAs($pic)
            ->test(ActionImprovementsRelationManager::class, [
                'ownerRecord' => $incident,
                'pageClass' => EditIncident::class,
            ])
            ->assertSuccessful()
            ->callTableAction('send_reminder', $action);

        $this->assertNull($action->refresh()->last_reminded_at, 'suppressed email must not stamp the row');
    }
}
