<?php

namespace Tests\Feature\Console;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ChannelFilteredNotification;
use App\Notifications\FundLossUnsettledReminder;
use App\Notifications\IncidentNotDoneReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendIncidentRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminds_pic_for_not_done_incident_and_stamps_timestamp(): void
    {
        Notification::fake();
        $pic = User::factory()->create();
        $incident = Incident::factory()->createQuietly([
            'pic_id' => $pic->id,
            'incident_status' => IncidentStatus::Open->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'potential_fund_loss' => 0,
            'recovered_fund' => 0,
            'incident_date' => now()->subDays(30),
        ]);

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertSentTo($pic, ChannelFilteredNotification::class, fn ($n) => $n->databaseType() === IncidentNotDoneReminder::class);
        $this->assertNotNull($incident->fresh()->last_reminded_at);
    }

    public function test_skips_recently_reminded_incidents(): void
    {
        Notification::fake();
        $pic = User::factory()->create();
        Incident::factory()->createQuietly([
            'pic_id' => $pic->id,
            'incident_status' => IncidentStatus::Open->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'potential_fund_loss' => 0,
            'recovered_fund' => 0,
            'incident_date' => now()->subDays(30),
            'last_reminded_at' => now()->subDay(),
        ]);

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_skips_completed_incidents(): void
    {
        Notification::fake();
        $pic = User::factory()->create();
        Incident::factory()->createQuietly([
            'pic_id' => $pic->id,
            'incident_status' => IncidentStatus::Completed->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'potential_fund_loss' => 0,
            'recovered_fund' => 0,
            'incident_date' => now()->subDays(30),
        ]);

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_reminds_pic_for_unsettled_fund_loss(): void
    {
        Notification::fake();
        $pic = User::factory()->create();
        Incident::factory()->createQuietly([
            'pic_id' => $pic->id,
            'incident_status' => IncidentStatus::Completed->value,
            'fund_status' => FundStatus::ConfirmedLoss->value,
            'potential_fund_loss' => 1000000,
            'recovered_fund' => 0,
            'incident_date' => now()->subDays(30),
        ]);

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertSentTo($pic, ChannelFilteredNotification::class, fn ($n) => $n->databaseType() === FundLossUnsettledReminder::class);
    }

    public function test_skips_settled_fund_loss(): void
    {
        Notification::fake();
        $pic = User::factory()->create();
        Incident::factory()->createQuietly([
            'pic_id' => $pic->id,
            'incident_status' => IncidentStatus::Completed->value,
            'fund_status' => FundStatus::ConfirmedLoss->value,
            'potential_fund_loss' => 1000000,
            'recovered_fund' => 1000000,
            'incident_date' => now()->subDays(30),
        ]);

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_global_kill_switch_disables_all_reminders(): void
    {
        Notification::fake();
        Setting::set('netcore_enabled', false);
        $pic = User::factory()->create();
        Incident::factory()->createQuietly([
            'pic_id' => $pic->id,
            'incident_status' => IncidentStatus::Open->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'potential_fund_loss' => 0,
            'recovered_fund' => 0,
            'incident_date' => now()->subDays(30),
        ]);

        $this->artisan('reminders:send-incidents')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
