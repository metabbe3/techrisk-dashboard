<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Setting;
use App\Notifications\BaseNotification;
use App\Notifications\FundLossUnsettledReminder;
use App\Notifications\IncidentNotDoneReminder;
use App\Support\ReminderMail;
use Illuminate\Console\Command;

/**
 * Sends Netcore email reminders for incidents that are:
 *   1. Not done — incident_status != Completed and older than the configured
 *      age threshold.
 *   2. Fund loss unsettled — fund_status is Confirmed loss / Potential recovery
 *      with outstanding (potential - recovered) > 0.
 * Both lanes are throttled by incidents.last_reminded_at and gated by the
 * Email Settings toggles + the global netcore_enabled kill-switch.
 * PIC-only (owner rule 2026-10-08): admins/team-leads are never emailed by
 * these lanes — no escalation path. One combined email per incident (all
 * PICs in the TO line) instead of one mail per PIC (owner 2026-10-09).
 */
class SendIncidentReminders extends Command
{
    protected $signature = 'reminders:send-incidents';

    protected $description = 'Send Netcore email reminders for not-done incidents and unsettled fund losses.';

    public function handle(): int
    {
        if (! Setting::get('netcore_enabled', true)) {
            $this->info('Email reminders disabled (netcore_enabled=false).');

            return self::SUCCESS;
        }

        $interval = (int) Setting::get('reminder_remind_interval_days', 7);

        if (Setting::get('incident_not_done_reminder_enabled', true)) {
            $this->sendNotDoneReminders($interval);
        }

        if (Setting::get('fund_loss_reminder_enabled', true)) {
            $this->sendFundLossReminders($interval);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function sendNotDoneReminders(int $interval): void
    {
        $thresholdDays = (int) Setting::get('incident_not_done_reminder_days', 7);

        $incidents = $this->dueIncidents($interval)
            ->whereNot('incident_status', IncidentStatus::Completed->value)
            ->whereDate('incident_date', '<=', now()->subDays($thresholdDays))
            ->get();

        $this->info("Found {$incidents->count()} not-done incidents due for a reminder.");

        foreach ($incidents as $incident) {
            $notification = new IncidentNotDoneReminder($incident);
            $this->remindPics($incident, $notification, 'not-done reminder');
        }
    }

    private function sendFundLossReminders(int $interval): void
    {
        $incidents = $this->dueIncidents($interval)
            ->whereIn('fund_status', [FundStatus::ConfirmedLoss->value, FundStatus::PotentialRecovery->value])
            ->whereColumn('potential_fund_loss', '>', 'recovered_fund')
            ->get();

        $this->info("Found {$incidents->count()} incidents with unsettled fund loss.");

        foreach ($incidents as $incident) {
            $notification = new FundLossUnsettledReminder($incident);
            $this->remindPics($incident, $notification, 'fund-loss reminder');
        }
    }

    /**
     * In-app notification per PIC + ONE combined email for all of them
     * (owner rule 2026-10-09 — one Netcore send instead of N).
     */
    private function remindPics(Incident $incident, BaseNotification $notification, string $label): void
    {
        $pics = $incident->pics;

        $pics->each(fn ($pic) => $pic->notify($notification));

        if ($pics->isNotEmpty()) {
            ReminderMail::send($pics, $notification);

            $emails = $pics->pluck('email')->implode(', ');
            $this->line("  → {$label}: {$incident->no} to PICs [{$emails}]");
        }

        $incident->forceFill(['last_reminded_at' => now()])->saveQuietly();
    }

    /**
     * Base query: not-yet-reminded (or past the throttle interval) incidents.
     */
    private function dueIncidents(int $interval)
    {
        return Incident::with('pics')
            ->where(function ($query) use ($interval) {
                $query->whereNull('last_reminded_at')
                    ->orWhere('last_reminded_at', '<=', now()->subDays(max($interval, 1)));
            });
    }
}
