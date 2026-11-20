<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/**
 * Users-page "Sync from Entra" button. The command output lands as a
 * database notification (sidebar bell) — the sync itself takes minutes.
 */
class SyncMicrosoftDirectoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    // Same envelope as ExtractInvestigationFormSource (< worker 660).
    public int $timeout = 600;

    public function __construct(public int $userId) {}

    public function handle(): void
    {
        $exit = Artisan::call('users:sync-directory');
        $output = trim(Artisan::output());

        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        Notification::make()
            ->title($exit === 0 ? 'Directory sync completed' : 'Directory sync failed')
            ->body($output)
            ->{$exit === 0 ? 'success' : 'danger'}()
            ->sendToDatabase($user);
    }
}
