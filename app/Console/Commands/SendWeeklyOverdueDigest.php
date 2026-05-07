<?php

namespace App\Console\Commands;

use App\Models\ActionImprovement;
use App\Models\User;
use App\Notifications\WeeklyOverdueDigest;
use Illuminate\Console\Command;

class SendWeeklyOverdueDigest extends Command
{
    protected $signature = 'reminders:send-weekly-overdue-digest';

    protected $description = 'Send weekly digest of overdue action improvements to admins.';

    public function handle()
    {
        $overdueActions = ActionImprovement::with('incident')
            ->where('status', 'pending')
            ->where('due_date', '<', now()->startOfDay())
            ->orderBy('due_date')
            ->get();

        if ($overdueActions->isEmpty()) {
            $this->info('No overdue action improvements. Skipping digest.');

            return;
        }

        $this->info("Found {$overdueActions->count()} overdue action improvements.");

        $admins = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->get();

        foreach ($admins as $admin) {
            $admin->notify(new WeeklyOverdueDigest($overdueActions, $overdueActions->count()));
            $this->info("Sent weekly digest to {$admin->email}");
        }

        $this->info('Done.');
    }
}
