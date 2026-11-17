<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InvestigationFormRecipient;
use App\Models\InvestigationFormRequest;
use App\Models\User;
use App\Services\InvestigationForm\InvestigationFormSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * investigation-forms:send-reminders — interval + final-reminder due rules,
 * the excluded states, token rotation per reminder and the off switch.
 */
class InvestigationFormReminderTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(array $overrides = []): array
    {
        $incident = \App\Models\Incident::factory()->create();
        $request = InvestigationFormRequest::create([
            'incident_id' => $incident->id,
            'team' => 'Payments',
            'status' => $overrides['status'] ?? InvestigationFormRequest::STATUS_PENDING,
            'expires_at' => $overrides['expires_at'] ?? now()->addDays(14),
            'created_by' => User::factory()->create()->id,
        ]);
        $plain = InvestigationFormRecipient::newToken();
        $recipient = $request->recipients()->create([
            'email' => 'a@x.com',
            'token_hash' => InvestigationFormRecipient::hashToken($plain),
            'emailed_at' => $overrides['emailed_at'] ?? now()->subDays(8),
            'last_reminded_at' => $overrides['last_reminded_at'] ?? null,
            'revoked_at' => $overrides['revoked_at'] ?? null,
        ]);

        return [$request, $recipient, $plain];
    }

    public function test_reminder_fires_after_interval_and_rotates_the_token(): void
    {
        [, $recipient, $plain] = $this->makeRequest();
        Mail::fake();

        $this->artisan('investigation-forms:send-reminders')->assertSuccessful();

        Mail::assertQueued(\App\Mail\InvestigationFormRequestMail::class, 1);

        $recipient->refresh();
        $this->assertNotSame(InvestigationFormRecipient::hashToken($plain), $recipient->token_hash, 'reminder must rotate the token');
        $this->assertNotNull($recipient->last_reminded_at);
        $this->assertSame(1, $recipient->reminder_count);

        // The rotated link works; the old one is dead.
        $this->assertTrue($recipient->linkIsValid());
    }

    public function test_no_reminder_before_interval(): void
    {
        $this->makeRequest(['emailed_at' => now()->subDays(6)]); // interval default 7
        Mail::fake();

        $this->artisan('investigation-forms:send-reminders')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_final_reminder_fires_days_before_expiry_even_inside_interval(): void
    {
        // Emailed 1 day ago (interval 7 not due), 2 days left (final reminder due).
        $this->makeRequest([
            'emailed_at' => now()->subDay(),
            'expires_at' => now()->addDays(2),
        ]);
        Mail::fake();

        $this->artisan('investigation-forms:send-reminders')->assertSuccessful();

        Mail::assertQueued(\App\Mail\InvestigationFormRequestMail::class, 1);
    }

    public function test_submitted_revoked_expired_requests_and_revoked_recipients_get_nothing(): void
    {
        $this->makeRequest(['status' => InvestigationFormRequest::STATUS_SUBMITTED]);
        $this->makeRequest(['status' => InvestigationFormRequest::STATUS_REVOKED]);
        $this->makeRequest(['expires_at' => now()->subHour()]);
        $this->makeRequest(['revoked_at' => now()]);
        Mail::fake();

        $this->artisan('investigation-forms:send-reminders')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_reminders_off_sends_nothing(): void
    {
        \App\Models\Setting::set(InvestigationFormSettings::KEY_REMINDERS_ENABLED, false);
        $this->makeRequest();
        Mail::fake();

        $this->artisan('investigation-forms:send-reminders')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_interval_setting_drives_the_due_rule(): void
    {
        \App\Models\Setting::set(InvestigationFormSettings::KEY_REMINDER_INTERVAL_DAYS, 4);
        $this->makeRequest(['emailed_at' => now()->subDays(4)]);
        Mail::fake();

        $this->artisan('investigation-forms:send-reminders')->assertSuccessful();

        Mail::assertQueued(\App\Mail\InvestigationFormRequestMail::class, 1);
    }

    public function test_command_is_registered_in_the_daily_schedule(): void
    {
        $known = collect(Schedule::events())
            ->filter(fn ($event): bool => str_contains((string) ($event->command ?? ''), 'investigation-forms:send-reminders'));

        $this->assertSame(1, $known->count(), 'schedule must register exactly one investigation-forms:send-reminders event');
    }
}
