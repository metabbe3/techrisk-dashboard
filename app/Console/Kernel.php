<?php

namespace App\Console;

use App\Models\ReportTemplate;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('reminders:send-action-improvements')->dailyAt('08:00');

        // Weekly overdue digest for admins — Mondays at 9 AM
        $schedule->command('reminders:send-weekly-overdue-digest')->weeklyOn(1, '09:00');

        // Clean up old notifications - runs daily at 2 AM
        $schedule->command('notifications:clean')->dailyAt('02:00');

        // Re-index stale RAG documents daily at 2:30 AM
        $schedule->command('rag:reindex-stale')->dailyAt('02:30');

        $dailyTemplates = ReportTemplate::where('schedule', 'daily')->get();
        foreach ($dailyTemplates as $template) {
            $schedule->command('app:send-report', [$template->id])->daily();
        }

        $weeklyTemplates = ReportTemplate::where('schedule', 'weekly')->get();
        foreach ($weeklyTemplates as $template) {
            $schedule->command('app:send-report', [$template->id])->weekly();
        }

        $monthlyTemplates = ReportTemplate::where('schedule', 'monthly')->get();
        foreach ($monthlyTemplates as $template) {
            $schedule->command('app:send-report', [$template->id])->monthly();
        }
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
