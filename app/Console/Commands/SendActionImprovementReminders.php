<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\GroupNotificationMail;
use App\Models\ActionImprovement;
use App\Models\User;
use App\Notifications\ActionImprovementDueSoon;
use App\Notifications\ActionImprovementEscalated;
use App\Notifications\ActionImprovementOverdue;
use App\Support\MailSettings;
use App\Support\ReminderMail;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

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

        // Open work (owner 2026-11-17): on_progress rows are accepted AND
        // started — chased exactly like pending. Only done/cancelled/tbd stop
        // the chase. Date-TBD semantics extend to them: no due date = never
        // chased (the date filters below already handle that).
        $openStatuses = ActionImprovement::openStatuses();

        // 1. Due in exactly 7 days
        $dueSoonActions = ActionImprovement::with('incident.pics')
            ->where('reminder', true)
            ->whereIn('status', $openStatuses)
            ->whereDate('due_date', '=', $today->copy()->addDays(7)->toDateString())
            ->get();

        $this->info("Found {$dueSoonActions->count()} action improvements due in 7 days.");

        // 2. Overdue
        $overdueActions = ActionImprovement::with('incident.pics')
            ->where('reminder', true)
            ->whereIn('status', $openStatuses)
            ->where('due_date', '<', $today->toDateString())
            ->get();

        $this->info("Found {$overdueActions->count()} overdue action improvements.");

        // reminder_frequency throttles the daily overdue batch: weekly skips
        // days within 7 of the last email, biweekly within 14 (null = daily,
        // unchanged). The form has offered these options all along — this is
        // the first code that reads them.
        $overdueActions = $overdueActions->filter(fn (ActionImprovement $action) => $this->reminderDue($action))->values();

        // One lookup for every PIC email across both batches (was one
        // query per action per email below). Keyed lowercase to keep the old
        // case-insensitive WHERE semantics. Must run BEFORE the due-soon
        // loop — when it ran after, due-soon pic_email recipients resolved
        // to nobody (only incident pics were reached).
        $this->usersByEmail = User::whereIn(
            'email',
            $dueSoonActions->merge($overdueActions)
                ->flatMap(fn ($a) => $a->pic_email ?? [])
                ->filter()
                ->unique()
                ->values()
        )->get()->keyBy(fn ($u) => strtolower($u->email))->all();

        foreach ($dueSoonActions as $action) {
            $this->sendDueSoonNotification($action);
        }

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
        $action->recordReminderSent(ReminderMail::send($recipients, new ActionImprovementDueSoon($action, $daysRemaining)));

        $this->logRecipients($action, 'due soon reminder', $recipients);
    }

    private function sendOverdueNotification(ActionImprovement $action): void
    {
        $daysOverdue = (int) (now()->diffInDays($action->due_date, false) * -1);
        $recipients = $this->recipientsFor($action);

        $recipients->each(fn ($user) => $user->notify(new ActionImprovementOverdue($action, $daysOverdue)));
        $action->recordReminderSent(ReminderMail::send($recipients, new ActionImprovementOverdue($action, $daysOverdue)));

        // Email kill-switch ON → escalation must NOT advance: the rung was
        // informed only if an email actually went out. In-app overdue
        // notification above still fires.
        if (MailSettings::enabled()) {
            $this->escalateIfDue($action, $daysOverdue);
        }

        $this->logRecipients($action, 'overdue notification', $recipients);
    }

    /**
     * Org-tree escalation (owner request 2026-10-13): every 2 weeks overdue
     * moves one manager up the PIC directory chain — L1 at 14 days, L2 at 28,
     * L3 at 42 (capped, which also bounds any manager cycle). Each level
     * fires exactly once (escalation_level persisted). A row already deep in
     * overdue on first run informs every rung it passed, not just the top;
     * rungs above a too-short chain are skipped silently.
     */
    private function escalateIfDue(ActionImprovement $action, int $daysOverdue): void
    {
        $target = min(intdiv($daysOverdue, 14), 3);

        // Ancestors are directory users (users table hosts the PIC
        // directory + manager chain).
        $contacts = User::whereIn('email', $action->pic_email ?? [])->get();

        if ($target < 1 || $contacts->isEmpty()) {
            return;
        }

        $picNames = $contacts->pluck('name')->filter()->implode(', ') ?: implode(', ', $action->pic_email ?? []);
        $fired = 0;

        foreach (range(max(1, $action->escalation_level + 1), $target) as $level) {
            $ancestors = $contacts->map(fn ($c) => $c->ancestorAt($level))->filter()->unique('id')->values();

            if ($ancestors->isEmpty()) {
                break; // chain is shorter than this rung — nothing higher either
            }

            $notification = new ActionImprovementEscalated($action, $level, $daysOverdue, $picNames);

            // Deliberately bypasses mail preferences — escalation is the
            // owner's mandate, not a subscription (distinct from
            // ReminderMail::send).
            Mail::to($ancestors->all())->send(new GroupNotificationMail($notification->toMailForGroup($ancestors->all())));
            $ancestors->each(fn ($user) => $user->notify($notification));
            $fired = $level;

            $this->info("Escalated L{$level} for: {$action->title} to [".implode(', ', $ancestors->pluck('email')->all()).']');
        }

        if ($fired > $action->escalation_level) {
            $action->forceFill([
                'escalation_level' => $fired,
                'last_escalated_at' => now(),
            ])->saveQuietly();
        }
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

    /**
     * Whether the overdue batch should email this action today: inside the
     * reminder_frequency window since last_reminded_at = skip. Null
     * frequency or never-reminded = due.
     */
    private function reminderDue(ActionImprovement $action): bool
    {
        $intervalDays = match ($action->reminder_frequency) {
            'weekly' => 7,
            'biweekly' => 14,
            default => null,
        };

        if ($intervalDays === null || $action->last_reminded_at === null) {
            return true;
        }

        return $action->last_reminded_at->copy()->addDays($intervalDays)->isPast();
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
