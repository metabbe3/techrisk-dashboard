<?php

namespace App\Console\Commands;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\FundLossUnsettledReminder;
use App\Notifications\IncidentNotDoneReminder;
use Illuminate\Console\Command;

/**
 * Sends Netcore email reminders for incidents that are:
 *   1. Not done — incident_status != Completed and older than the configured
 *      age threshold (escalates to admins when very old).
 *   2. Fund loss unsettled — fund_status is Confirmed loss / Potential recovery
 *      with outstanding (potential - recovered) > 0.
 * Both lanes are throttled by incidents.last_reminded_at and gated by the
 * Email Settings toggles + the global netcore_enabled kill-switch.
 * Mirrors SendActionImprovementReminders (PIC notify + admin escalation + dedup).
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
        $escalateDays = $thresholdDays * 2;
        $admins = $this->admins();

        $incidents = $this->dueIncidents($interval)
            ->whereNot('incident_status', IncidentStatus::Completed->value)
            ->whereDate('incident_date', '<=', now()->subDays($thresholdDays))
            ->get();

        $this->info("Found {$incidents->count()} not-done incidents due for a reminder.");

        foreach ($incidents as $incident) {
            $notified = [];

            if ($incident->pic && ! in_array($incident->pic->id, $notified)) {
                $incident->pic->notify(new IncidentNotDoneReminder($incident));
                $notified[] = $incident->pic->id;
                $this->line("  → not-done reminder: {$incident->no} to PIC {$incident->pic->email}");
            }

            // Escalate to admins when the incident is very old.
            $ageDays = $incident->incident_date?->diffInDays(now()) ?? 0;
            if ($ageDays >= $escalateDays) {
                foreach ($admins as $admin) {
                    if (! in_array($admin->id, $notified)) {
                        $admin->notify(new IncidentNotDoneReminder($incident));
                        $notified[] = $admin->id;
                    }
                }
            }

            $incident->forceFill(['last_reminded_at' => now()])->saveQuietly();
        }
    }

    private function sendFundLossReminders(int $interval): void
    {
        $admins = $this->admins();

        $incidents = $this->dueIncidents($interval)
            ->whereIn('fund_status', [FundStatus::ConfirmedLoss->value, FundStatus::PotentialRecovery->value])
            ->whereColumn('potential_fund_loss', '>', 'recovered_fund')
            ->get();

        $this->info("Found {$incidents->count()} incidents with unsettled fund loss.");

        foreach ($incidents as $incident) {
            $notified = [];

            if ($incident->pic && ! in_array($incident->pic->id, $notified)) {
                $incident->pic->notify(new FundLossUnsettledReminder($incident));
                $notified[] = $incident->pic->id;
                $this->line("  → fund-loss reminder: {$incident->no} to PIC {$incident->pic->email}");
            }

            foreach ($admins as $admin) {
                if (! in_array($admin->id, $notified)) {
                    $admin->notify(new FundLossUnsettledReminder($incident));
                    $notified[] = $admin->id;
                }
            }

            $incident->forceFill(['last_reminded_at' => now()])->saveQuietly();
        }
    }

    /**
     * Base query: not-yet-reminded (or past the throttle interval) incidents.
     */
    private function dueIncidents(int $interval)
    {
        return Incident::with('pic')
            ->where(function ($query) use ($interval) {
                $query->whereNull('last_reminded_at')
                    ->orWhere('last_reminded_at', '<=', now()->subDays(max($interval, 1)));
            });
    }

    private function admins()
    {
        return User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'team-lead']))->get();
    }
}
