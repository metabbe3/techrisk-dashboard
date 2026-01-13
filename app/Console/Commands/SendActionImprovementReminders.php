<?php

namespace App\Console\Commands;

use App\Models\ActionImprovement;
use App\Notifications\ActionImprovementDueSoon;
use App\Notifications\ActionImprovementOverdue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Carbon\Carbon;

class SendActionImprovementReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminders:send-action-improvements';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send email reminders for action improvements that are due soon or overdue.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for action improvements...');

        $today = Carbon::now()->startOfDay();

        // Send reminders for items due in 7 days
        $dueSoonActions = ActionImprovement::with('incident.pic')
            ->where('reminder', true)
            ->where('status', 'pending')
            ->whereDate('due_date', '=', $today->copy()->addDays(7)->toDateString())
            ->get();

        $this->info("Found {$dueSoonActions->count()} action improvements due in 7 days.");

        foreach ($dueSoonActions as $action) {
            $this->sendDueSoonNotification($action);
        }

        // Send notifications for overdue items
        $overdueActions = ActionImprovement::with('incident.pic')
            ->where('reminder', true)
            ->where('status', 'pending')
            ->where('due_date', '<', $today->toDateString())
            ->get();

        $this->info("Found {$overdueActions->count()} overdue action improvements.");

        foreach ($overdueActions as $action) {
            $this->sendOverdueNotification($action);
        }

        $this->info('Done.');
    }

    /**
     * Send due soon notification for an action improvement.
     */
    private function sendDueSoonNotification(ActionImprovement $action): void
    {
        $daysRemaining = now()->diffInDays($action->due_date, false);

        // Notify PIC emails
        foreach ($action->pic_email as $picEmail) {
            $user = \App\Models\User::where('email', $picEmail)->first();
            if ($user) {
                Notification::send($user, new ActionImprovementDueSoon($action, $daysRemaining));
                $this->info("Sent due soon reminder for: {$action->title} to {$picEmail}");
            }
        }

        // Notify incident PIC
        $incident = $action->incident;
        if ($incident && $incident->pic) {
            Notification::send($incident->pic, new ActionImprovementDueSoon($action, $daysRemaining));
            $this->info("Sent due soon reminder for: {$action->title} to incident PIC {$incident->pic->email}");
        }
    }

    /**
     * Send overdue notification for an action improvement.
     */
    private function sendOverdueNotification(ActionImprovement $action): void
    {
        $daysOverdue = now()->diffInDays($action->due_date, false) * -1;

        // Notify PIC emails
        foreach ($action->pic_email as $picEmail) {
            $user = \App\Models\User::where('email', $picEmail)->first();
            if ($user) {
                Notification::send($user, new ActionImprovementOverdue($action, $daysOverdue));
                $this->info("Sent overdue notification for: {$action->title} to {$picEmail}");
            }
        }

        // Notify incident PIC
        $incident = $action->incident;
        if ($incident && $incident->pic) {
            Notification::send($incident->pic, new ActionImprovementOverdue($action, $daysOverdue));
            $this->info("Sent overdue notification for: {$action->title} to incident PIC {$incident->pic->email}");
        }
    }
}
