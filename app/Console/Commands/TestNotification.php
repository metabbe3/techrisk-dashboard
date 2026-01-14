<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\AssignedAsPicNotification;
use App\Models\Incident;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class TestNotification extends Command
{
    protected $signature = 'notification:test {user_id}';
    protected $description = 'Send a test notification directly via Notification facade';

    public function handle()
    {
        $userId = $this->argument('user_id');
        $user = User::find($userId);

        if (!$user) {
            $this->error("User with ID {$userId} not found.");
            return 1;
        }

        // Get an incident to use
        $incident = Incident::first();
        if (!$incident) {
            $this->error("No incidents found in database.");
            return 1;
        }

        $this->info("Sending test notification to user: {$user->name} (ID: {$user->id})");
        $this->info("Using incident: {$incident->title} (ID: {$incident->id})");

        // Count before
        $beforeCount = $user->unreadNotifications()->count();
        $this->info("Unread notifications before: {$beforeCount}");

        // Send notification directly via Notification facade (bypasses User::notify())
        $notification = new AssignedAsPicNotification($incident);
        Notification::send($user, $notification);

        // Count after
        $afterCount = $user->unreadNotifications()->count();
        $this->info("Unread notifications after: {$afterCount}");

        if ($afterCount > $beforeCount) {
            $this->info("✅ Notification created successfully!");
            $this->info("Check the bell icon in Filament panel.");

            // Show the notification details
            $latestNotification = $user->unreadNotifications()->latest()->first();
            $this->table(
                ['Field', 'Value'],
                [
                    ['ID', $latestNotification->id],
                    ['Type', $latestNotification->type],
                    ['Notifiable ID', $latestNotification->notifiable_id],
                    ['Notifiable Type', $latestNotification->notifiable_type],
                    ['Read At', $latestNotification->read_at ?? 'null'],
                    ['Data', json_encode($latestNotification->data, JSON_PRETTY_PRINT)],
                ]
            );
        } else {
            $this->error("❌ Notification was NOT created.");
        }

        return 0;
    }
}
