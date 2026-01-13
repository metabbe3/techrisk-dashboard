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

        // Clean up old notifications - runs daily at 2 AM
        $schedule->command('notifications:clean')->dailyAt('02:00');

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
