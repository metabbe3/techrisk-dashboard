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
            ->get();

        foreach ($actionImprovements as $actionImprovement) {
            $dueDate = Carbon::parse($actionImprovement->due_date);
            $now = Carbon::now();

            if ($now->gt($dueDate)) { // Skip if due date is in the past
                continue;
            }

            if ($now->diffInDays($dueDate) <= 3) {
                foreach ($actionImprovement->pic_email as $picEmail) {
                    Notification::route('mail', $picEmail)
                        ->notify(new ActionImprovementReminder($actionImprovement));
                    $this->info('Sent reminder for: ' . $actionImprovement->title . ' to ' . $picEmail);
                }
            }
        }

        $this->info('Done.');
    }
}
