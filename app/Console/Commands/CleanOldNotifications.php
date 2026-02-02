<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanOldNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:clean
                            {--read-days=30 : Delete read notifications older than X days}
                            {--unread-days=90 : Delete unread notifications older than X days}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old notifications from the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $readDays = (int) $this->option('read-days');
        $unreadDays = (int) $this->option('unread-days');
        $dryRun = $this->option('dry-run');

        $this->info('Cleaning old notifications...');
        $this->info("Read notifications older than {$readDays} days will be deleted");
        $this->info("Unread notifications older than {$unreadDays} days will be deleted");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No notifications will be deleted');
        }

        $totalDeleted = 0;

        // Clean up read notifications
        $readDate = Carbon::now()->subDays($readDays);
        $readQuery = DB::table('notifications')
            ->whereNotNull('read_at')
            ->where('read_at', '<', $readDate);

        $readCount = $readQuery->count();
        $this->info("Found {$readCount} read notifications to delete");

        if ($readCount > 0) {
            if ($dryRun) {
                $this->table(
                    ['ID', 'Type', 'Read At', 'Created At'],
                    $readQuery->limit(10)->get()->map(function ($n) {
                        return [
                            substr($n->id, 0, 8).'...',
                            $n->type,
                            $n->read_at,
                            $n->created_at,
                        ];
                    })->toArray()
                );
            } else {
                $deleted = $readQuery->delete();
                $totalDeleted += $deleted;
                $this->info("Deleted {$deleted} read notifications");
            }
        }

        // Clean up unread notifications
        $unreadDate = Carbon::now()->subDays($unreadDays);
        $unreadQuery = DB::table('notifications')
            ->whereNull('read_at')
            ->where('created_at', '<', $unreadDate);

        $unreadCount = $unreadQuery->count();
        $this->info("Found {$unreadCount} unread notifications to delete");

        if ($unreadCount > 0) {
            if ($dryRun) {
                $this->table(
                    ['ID', 'Type', 'Created At'],
                    $unreadQuery->limit(10)->get()->map(function ($n) {
                        return [
                            substr($n->id, 0, 8).'...',
                            $n->type,
                            $n->created_at,
                        ];
                    })->toArray()
                );
            } else {
                $deleted = $unreadQuery->delete();
                $totalDeleted += $deleted;
                $this->info("Deleted {$deleted} unread notifications");
            }
        }

        $this->info("Total notifications deleted: {$totalDeleted}");

        return Command::SUCCESS;
    }
}
