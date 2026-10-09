<?php

namespace Tests\Feature\Console;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Mail\GroupNotificationMail;
use App\Models\Incident;
use App\Models\NotificationPreference;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ChannelFilteredNotification;
use App\Notifications\FundLossUnsettledReminder;
use App\Notifications\IncidentNotDoneReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendIncidentRemindersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Multi-PIC: attaching a PIC fires the pivot assignment notification —
     * that is setup noise here, so re-fake after attaching.
     */
    private function incidentWithPic(array $attributes, User $pic): Incident
    {
        $incident = Incident::factory()->createQuietly($attributes);
        $incident->pics()->attach($pic->id);
        Notification::fake();

        return $incident;
    }

    public function test_reminds_pic_for_not_done_incident_and_stamps_timestamp(): void
    {
        Notification::fake();
        $pic = User::factory()->create();
        $incident = $this->incidentWithPic([
            'incident_status' => IncidentStatus::Open->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'potential_fund_loss' => 0,
            'recovered_fund' => 0,
            'incident_date' => now()->subDays(30),
        ], $pic);

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertSentTo($pic, ChannelFilteredNotification::class, fn ($n) => $n->databaseType() === IncidentNotDoneReminder::class);
        $this->assertNotNull($incident->fresh()->last_reminded_at);
    }

    public function test_reminds_all_pics_for_not_done_incident(): void
    {
        Notification::fake();
        $pic1 = User::factory()->create();
        $pic2 = User::factory()->create();
        $incident = Incident::factory()->createQuietly([
            'incident_status' => IncidentStatus::Open->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'potential_fund_loss' => 0,
            'recovered_fund' => 0,
            'incident_date' => now()->subDays(30),
        ]);
        $incident->pics()->sync([$pic1->id, $pic2->id]);
        Notification::fake();

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertSentTo($pic1, ChannelFilteredNotification::class, fn ($n) => $n->databaseType() === IncidentNotDoneReminder::class);
        Notification::assertSentTo($pic2, ChannelFilteredNotification::class, fn ($n) => $n->databaseType() === IncidentNotDoneReminder::class);
    }

    public function test_skips_recently_reminded_incidents(): void
    {
        Notification::fake();
        $pic = User::factory()->create();
        $this->incidentWithPic([
            'incident_status' => IncidentStatus::Open->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'potential_fund_loss' => 0,
            'recovered_fund' => 0,
            'incident_date' => now()->subDays(30),
            'last_reminded_at' => now()->subDay(),
        ], $pic);

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_skips_completed_incidents(): void
    {
        Notification::fake();
        $pic = User::factory()->create();
        $this->incidentWithPic([
            'incident_status' => IncidentStatus::Completed->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'potential_fund_loss' => 0,
            'recovered_fund' => 0,
            'incident_date' => now()->subDays(30),
        ], $pic);

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_reminds_pic_for_unsettled_fund_loss(): void
    {
        Notification::fake();
        $pic = User::factory()->create();
        $this->incidentWithPic([
            'incident_status' => IncidentStatus::Completed->value,
            'fund_status' => FundStatus::ConfirmedLoss->value,
            'potential_fund_loss' => 1000000,
            'recovered_fund' => 0,
            'incident_date' => now()->subDays(30),
        ], $pic);

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertSentTo($pic, ChannelFilteredNotification::class, fn ($n) => $n->databaseType() === FundLossUnsettledReminder::class);
    }

    public function test_skips_settled_fund_loss(): void
    {
        Notification::fake();
        $pic = User::factory()->create();
        $this->incidentWithPic([
            'incident_status' => IncidentStatus::Completed->value,
            'fund_status' => FundStatus::ConfirmedLoss->value,
            'potential_fund_loss' => 1000000,
            'recovered_fund' => 1000000,
            'incident_date' => now()->subDays(30),
        ], $pic);

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_multi_pic_reminder_sends_one_combined_email(): void
    {
        Notification::fake();
        Mail::fake();
        $pic1 = User::factory()->create();
        $pic2 = User::factory()->create();
        $incident = Incident::factory()->createQuietly([
            'incident_status' => IncidentStatus::Open->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'potential_fund_loss' => 0,
            'recovered_fund' => 0,
            'incident_date' => now()->subDays(30),
        ]);
        $incident->pics()->sync([$pic1->id, $pic2->id]);
        Notification::fake(); // re-fake after attach noise

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        // One email with both PICs in the TO line (owner rule 2026-10-09:
        // combine recipients to cut Netcore send cost).
        Mail::assertSent(GroupNotificationMail::class, 1);
        Mail::assertSent(GroupNotificationMail::class, fn ($mail) => $mail->hasTo($pic1->email) && $mail->hasTo($pic2->email));

        // Both still get the in-app notification.
        Notification::assertSentTo($pic1, ChannelFilteredNotification::class, fn ($n) => $n->databaseType() === IncidentNotDoneReminder::class);
        Notification::assertSentTo($pic2, ChannelFilteredNotification::class, fn ($n) => $n->databaseType() === IncidentNotDoneReminder::class);
    }

    public function test_reminder_email_skips_users_with_mail_preference_disabled(): void
    {
        Notification::fake();
        Mail::fake();
        $pic1 = User::factory()->create();
        $pic2 = User::factory()->create();
        NotificationPreference::forUser($pic2)->update(['email_incident_not_done_reminder' => false]);
        $incident = Incident::factory()->createQuietly([
            'incident_status' => IncidentStatus::Open->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'potential_fund_loss' => 0,
            'recovered_fund' => 0,
            'incident_date' => now()->subDays(30),
        ]);
        $incident->pics()->sync([$pic1->id, $pic2->id]);
        Notification::fake(); // re-fake after attach noise

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        // Opted-out user is excluded from the combined TO line…
        Mail::assertSent(GroupNotificationMail::class, fn ($mail) => $mail->hasTo($pic1->email) && ! $mail->hasTo($pic2->email));

        // …but still gets the in-app notification.
        Notification::assertSentTo($pic2, ChannelFilteredNotification::class, fn ($n) => $n->databaseType() === IncidentNotDoneReminder::class);
    }

    public function test_not_done_reminder_never_notifies_admins_or_team_leads(): void
    {
        Notification::fake();
        $pic = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']));
        $lead = User::factory()->create();
        $lead->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'team-lead']));
        $this->incidentWithPic([
            'incident_status' => IncidentStatus::Open->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'potential_fund_loss' => 0,
            'recovered_fund' => 0,
            // 30 days old — past the old 14-day escalation threshold.
            'incident_date' => now()->subDays(30),
        ], $pic);

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertSentTo($pic, ChannelFilteredNotification::class, fn ($n) => $n->databaseType() === IncidentNotDoneReminder::class);
        Notification::assertNotSentTo($admin, ChannelFilteredNotification::class);
        Notification::assertNotSentTo($lead, ChannelFilteredNotification::class);
    }

    public function test_fund_loss_reminder_never_notifies_admins_or_team_leads(): void
    {
        Notification::fake();
        $pic = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']));
        $lead = User::factory()->create();
        $lead->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'team-lead']));
        $this->incidentWithPic([
            'incident_status' => IncidentStatus::Completed->value,
            'fund_status' => FundStatus::ConfirmedLoss->value,
            'potential_fund_loss' => 1000000,
            'recovered_fund' => 0,
            'incident_date' => now()->subDays(30),
        ], $pic);

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertSentTo($pic, ChannelFilteredNotification::class, fn ($n) => $n->databaseType() === FundLossUnsettledReminder::class);
        Notification::assertNotSentTo($admin, ChannelFilteredNotification::class);
        Notification::assertNotSentTo($lead, ChannelFilteredNotification::class);
    }

    public function test_global_kill_switch_disables_all_reminders(): void
    {
        Notification::fake();
        Setting::set('netcore_enabled', false);
        $pic = User::factory()->create();
        $this->incidentWithPic([
            'incident_status' => IncidentStatus::Open->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'potential_fund_loss' => 0,
            'recovered_fund' => 0,
            'incident_date' => now()->subDays(30),
        ], $pic);

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
