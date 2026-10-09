<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActionImprovement;
use App\Models\User;
use App\Notifications\ActionImprovementDueSoon;
use App\Notifications\ActionImprovementOverdue;
use App\Support\ReminderMail;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Sends reminders for pending action improvements with the reminder flag on:
 *   1. Due soon — exactly 7 days before the due date.
 *   2. Overdue — every day from the due date onward.
 * Recipients are the action's pic_email users plus the parent incident's PICs
 * (deduped). PIC-only (owner rule 2026-10-09): admins are never emailed by
 * this command — no escalation path. One combined email per action (all PICs
 * in the TO line) instead of one mail per PIC (owner rule 2026-10-09).
 */
class SendActionImprovementReminders extends Command
{
    protected $signature = 'reminders:send-action-improvements';

    protected $description = 'Send due-soon and overdue reminders for action improvements to their PICs.';

    /** @var array<string, User> PIC users by lowercase email — one query instead of per-action lookups */
    private array $usersByEmail = [];

    public function handle()
    {
        $this->info('Checking for action improvements...');

        $today = Carbon::now()->startOfDay();

        // 1. Due in exactly 7 days
        $dueSoonActions = ActionImprovement::with('incident.pics')
            ->where('reminder', true)
            ->where('status', 'pending')
            ->whereDate('due_date', '=', $today->copy()->addDays(7)->toDateString())
            ->get();

        $this->info("Found {$dueSoonActions->count()} action improvements due in 7 days.");

        foreach ($dueSoonActions as $action) {
            $this->sendDueSoonNotification($action);
        }

        // 2. Overdue
        $overdueActions = ActionImprovement::with('incident.pics')
            ->where('reminder', true)
            ->where('status', 'pending')
            ->where('due_date', '<', $today->toDateString())
            ->get();

        $this->info("Found {$overdueActions->count()} overdue action improvements.");

        // One lookup for every PIC email across both batches (was one
        // query per action per email below). Keyed lowercase to keep the old
        // case-insensitive WHERE semantics.
        $this->usersByEmail = User::whereIn(
            'email',
            $dueSoonActions->merge($overdueActions)
                ->flatMap(fn ($a) => $a->pic_email ?? [])
                ->filter()
                ->unique()
                ->values()
        )->get()->keyBy(fn ($u) => strtolower($u->email))->all();

        foreach ($overdueActions as $action) {
            $this->sendOverdueNotification($action);
        }

        $this->info('Done.');
    }

    private function sendDueSoonNotification(ActionImprovement $action): void
    {
        $daysRemaining = (int) now()->diffInDays($action->due_date, false);
        $recipients = $this->recipientsFor($action);

        $recipients->each(fn ($user) => $user->notify(new ActionImprovementDueSoon($action, $daysRemaining)));
        ReminderMail::send($recipients, new ActionImprovementDueSoon($action, $daysRemaining));

        $this->logRecipients($action, 'due soon reminder', $recipients);
    }

    private function sendOverdueNotification(ActionImprovement $action): void
    {
        $daysOverdue = (int) (now()->diffInDays($action->due_date, false) * -1);
        $recipients = $this->recipientsFor($action);

        $recipients->each(fn ($user) => $user->notify(new ActionImprovementOverdue($action, $daysOverdue)));
        ReminderMail::send($recipients, new ActionImprovementOverdue($action, $daysOverdue));

        $this->logRecipients($action, 'overdue notification', $recipients);
    }

    /**
     * The action's pic_email users (when they match a User) plus the parent
     * incident's PICs, deduped by id.
     *
     * @return Collection<int, User>
     */
    private function recipientsFor(ActionImprovement $action): Collection
    {
        $byEmail = collect($action->pic_email ?? [])
            ->filter()
            ->map(fn ($email) => $this->usersByEmail[strtolower($email)] ?? null)
            ->filter();

        return $byEmail
            ->merge($action->incident?->pics ?? collect())
            ->unique('id')
            ->values();
    }

    private function logRecipients(ActionImprovement $action, string $label, Collection $recipients): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        $emails = $recipients->pluck('email')->implode(', ');
        $this->info("Sent {$label} for: {$action->title} to [{$emails}]");
    }
}
