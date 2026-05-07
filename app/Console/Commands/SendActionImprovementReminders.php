<?php

namespace App\Console\Commands;

use App\Models\ActionImprovement;
use App\Models\User;
use App\Notifications\ActionImprovementDueSoon;
use App\Notifications\ActionImprovementEscalated;
use App\Notifications\ActionImprovementOverdue;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendActionImprovementReminders extends Command
{
    protected $signature = 'reminders:send-action-improvements';

    protected $description = 'Send reminders for action improvements: due soon, overdue, and escalated.';

    public function handle()
    {
        $this->info('Checking for action improvements...');

        $today = Carbon::now()->startOfDay();

        // 1. Due in exactly 7 days
        $dueSoonActions = ActionImprovement::with('incident.pic')
            ->where('reminder', true)
            ->where('status', 'pending')
            ->whereDate('due_date', '=', $today->copy()->addDays(7)->toDateString())
            ->get();

        $this->info("Found {$dueSoonActions->count()} action improvements due in 7 days.");

        foreach ($dueSoonActions as $action) {
            $this->sendDueSoonNotification($action);
        }

        // 2. Overdue (but less than 7 days — normal overdue to PIC)
        $overdueActions = ActionImprovement::with('incident.pic')
            ->where('reminder', true)
            ->where('status', 'pending')
            ->where('due_date', '<', $today->toDateString())
            ->where('due_date', '>=', $today->copy()->subDays(7)->toDateString())
            ->get();

        $this->info("Found {$overdueActions->count()} overdue action improvements (PIC notification).");

        foreach ($overdueActions as $action) {
            $this->sendOverdueNotification($action);
        }

        // 3. Overdue 7+ days — escalate to admins/team leads
        $escalatedActions = ActionImprovement::with('incident.pic')
            ->where('reminder', true)
            ->where('status', 'pending')
            ->where('due_date', '<', $today->copy()->subDays(7)->toDateString())
            ->get();

        $this->info("Found {$escalatedActions->count()} escalated action improvements (7+ days overdue).");

        foreach ($escalatedActions as $action) {
            $this->sendEscalatedNotification($action);
        }

        $this->info('Done.');
    }

    private function sendDueSoonNotification(ActionImprovement $action): void
    {
        $daysRemaining = now()->diffInDays($action->due_date, false);
        $notified = [];

        foreach ($action->pic_email as $picEmail) {
            $user = User::where('email', $picEmail)->first();
            if ($user && ! in_array($user->id, $notified)) {
                $user->notify(new ActionImprovementDueSoon($action, $daysRemaining));
                $notified[] = $user->id;
                $this->info("Sent due soon reminder for: {$action->title} to {$picEmail}");
            }
        }

        $incident = $action->incident;
        if ($incident && $incident->pic && ! in_array($incident->pic->id, $notified)) {
            $incident->pic->notify(new ActionImprovementDueSoon($action, $daysRemaining));
            $this->info("Sent due soon reminder for: {$action->title} to incident PIC {$incident->pic->email}");
        }
    }

    private function sendOverdueNotification(ActionImprovement $action): void
    {
        $daysOverdue = now()->diffInDays($action->due_date, false) * -1;
        $notified = [];

        foreach ($action->pic_email as $picEmail) {
            $user = User::where('email', $picEmail)->first();
            if ($user && ! in_array($user->id, $notified)) {
                $user->notify(new ActionImprovementOverdue($action, $daysOverdue));
                $notified[] = $user->id;
                $this->info("Sent overdue notification for: {$action->title} to {$picEmail}");
            }
        }

        $incident = $action->incident;
        if ($incident && $incident->pic && ! in_array($incident->pic->id, $notified)) {
            $incident->pic->notify(new ActionImprovementOverdue($action, $daysOverdue));
            $this->info("Sent overdue notification for: {$action->title} to incident PIC {$incident->pic->email}");
        }
    }

    private function sendEscalatedNotification(ActionImprovement $action): void
    {
        $daysOverdue = now()->diffInDays($action->due_date, false) * -1;

        // Still notify PIC with standard overdue
        $this->sendOverdueNotification($action);

        // Escalate to admins
        $admins = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->get();
        $notified = [];

        foreach ($action->pic_email as $picEmail) {
            $user = User::where('email', $picEmail)->first();
            if ($user) {
                $notified[] = $user->id;
            }
        }

        $incident = $action->incident;
        if ($incident && $incident->pic) {
            $notified[] = $incident->pic->id;
        }

        foreach ($admins as $admin) {
            if (! in_array($admin->id, $notified)) {
                $admin->notify(new ActionImprovementEscalated($action, $daysOverdue));
                $this->info("Escalated: {$action->title} ({$daysOverdue}d overdue) to admin {$admin->email}");
            }
        }
    }
}
