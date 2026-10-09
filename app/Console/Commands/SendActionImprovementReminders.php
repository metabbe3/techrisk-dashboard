<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActionImprovement;
use App\Models\User;
use App\Notifications\ActionImprovementDueSoon;
use App\Notifications\ActionImprovementOverdue;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Sends reminders for pending action improvements with the reminder flag on:
 *   1. Due soon — exactly 7 days before the due date.
 *   2. Overdue — every day from the due date onward.
 * Recipients are the action's pic_email users plus the parent incident's PICs
 * (deduped). PIC-only (owner rule 2026-10-09): admins are never emailed by
 * this command — no escalation path.
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
        $notified = [];

        foreach ($action->pic_email as $picEmail) {
            $user = $this->usersByEmail[strtolower($picEmail)] ?? null;
            if ($user && ! in_array($user->id, $notified)) {
                $user->notify(new ActionImprovementDueSoon($action, $daysRemaining));
                $notified[] = $user->id;
                $this->info("Sent due soon reminder for: {$action->title} to {$picEmail}");
            }
        }

        $incident = $action->incident;
        foreach ($incident?->pics ?? [] as $pic) {
            if (! in_array($pic->id, $notified)) {
                $pic->notify(new ActionImprovementDueSoon($action, $daysRemaining));
                $notified[] = $pic->id;
                $this->info("Sent due soon reminder for: {$action->title} to incident PIC {$pic->email}");
            }
        }
    }

    private function sendOverdueNotification(ActionImprovement $action): void
    {
        $daysOverdue = (int) (now()->diffInDays($action->due_date, false) * -1);
        $notified = [];

        foreach ($action->pic_email as $picEmail) {
            $user = $this->usersByEmail[strtolower($picEmail)] ?? null;
            if ($user && ! in_array($user->id, $notified)) {
                $user->notify(new ActionImprovementOverdue($action, $daysOverdue));
                $notified[] = $user->id;
                $this->info("Sent overdue notification for: {$action->title} to {$picEmail}");
            }
        }

        $incident = $action->incident;
        foreach ($incident?->pics ?? [] as $pic) {
            if (! in_array($pic->id, $notified)) {
                $pic->notify(new ActionImprovementOverdue($action, $daysOverdue));
                $notified[] = $pic->id;
                $this->info("Sent overdue notification for: {$action->title} to incident PIC {$pic->email}");
            }
        }
    }
}
