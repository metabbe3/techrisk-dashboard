<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InvestigationFormRecipient;
use App\Models\InvestigationFormRequest;
use App\Services\InvestigationForm\InvestigationFormSettings;
use App\Support\InvestigationFormMailFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Daily auto-reminder for pending investigation form requests. Each send
 * ROTATES the recipient's token (plain tokens are never stored) and emails
 * the fresh link — the previous link stops working. One bad address never
 * blocks the rest.
 */
class SendInvestigationFormReminders extends Command
{
    protected $signature = 'investigation-forms:send-reminders';

    protected $description = 'Send due reminders for pending investigation form requests (rotates each recipient link)';

    public function handle(): int
    {
        if (! InvestigationFormSettings::remindersEnabled()) {
            $this->info('Reminders are off.');

            return self::SUCCESS;
        }

        $intervalDays = InvestigationFormSettings::reminderIntervalDays();
        $finalDays = InvestigationFormSettings::finalReminderDays();
        $now = now();
        $sent = 0;

        $requests = InvestigationFormRequest::query()
            ->where('status', InvestigationFormRequest::STATUS_PENDING)
            ->where('expires_at', '>', $now)
            ->with('recipients')
            ->get();

        foreach ($requests as $request) {
            foreach ($request->recipients as $recipient) {
                if ($recipient->isRevoked()) {
                    continue;
                }

                $anchor = $recipient->last_reminded_at ?? $recipient->emailed_at;
                $intervalDue = $anchor !== null && $anchor->copy()->addDays($intervalDays)->lte($now);
                $daysLeft = $now->startOfDay()->diffInDays($request->expires_at->copy()->startOfDay(), false);
                $finalDue = $finalDays > 0 && $daysLeft !== false && $daysLeft <= $finalDays && $daysLeft >= 0;

                if (! $intervalDue && ! $finalDue) {
                    continue;
                }

                $plain = InvestigationFormRecipient::newToken();
                $recipient->update([
                    'token_hash' => InvestigationFormRecipient::hashToken($plain),
                    'last_reminded_at' => $now,
                    'reminder_count' => $recipient->reminder_count + 1,
                ]);

                ['mail' => $mail] = InvestigationFormMailFactory::reminderMail($recipient->fresh(), $plain);

                try {
                    Mail::to($recipient->email)->queue($mail);
                    $sent++;
                } catch (\Throwable $e) {
                    report($e);
                    $this->warn('Failed to queue reminder for '.$recipient->email.': '.$e->getMessage());
                }
            }
        }

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
