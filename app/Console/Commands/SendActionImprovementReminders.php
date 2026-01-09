<?php

namespace App\Console\Commands;

use App\Models\ActionImprovement;
use App\Notifications\ActionImprovementReminder;
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
    protected $description = 'Send email reminders for action improvements that are due soon.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Sending action improvement reminders...');

        $actionImprovements = ActionImprovement::where('reminder', true)
            ->where('status', 'pending')
            ->whereDate('due_date', '=', Carbon::now()->addDays(7)->toDateString())
            ->get();

        foreach ($actionImprovements as $actionImprovement) {
            // Notify PIC of Action Improvement
            foreach ($actionImprovement->pic_email as $picEmail) {
                $user = \App\Models\User::where('email', $picEmail)->first();
                if ($user) {
                    $user->notify(new ActionImprovementReminder($actionImprovement));
                    $this->info('Sent reminder for: ' . $actionImprovement->title . ' to ' . $picEmail);
                }
            }

            // Notify PIC of Incident
            $incident = $actionImprovement->incident;
            if ($incident && $incident->pic) {
                $incident->pic->notify(new ActionImprovementReminder($actionImprovement));
                $this->info('Sent reminder for: ' . $actionImprovement->title . ' to incident PIC ' . $incident->pic->email);
            }
        }

        $this->info('Done.');
    }
}
