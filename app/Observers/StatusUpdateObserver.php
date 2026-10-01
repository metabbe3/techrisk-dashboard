<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\StatusUpdate;
use App\Notifications\NewStatusUpdate;
use Illuminate\Support\Facades\Auth;

class StatusUpdateObserver
{
    /**
     * Handle the StatusUpdate "created" event.
     */
    public function created(StatusUpdate $statusUpdate): void
    {
        $incident = $statusUpdate->incident;

        // Notify every PIC who is not the one who created the status update
        if ($incident) {
            $currentUser = Auth::user();

            foreach ($incident->pics as $pic) {
                if (! $currentUser || $currentUser->id !== $pic->id) {
                    $pic->notify(new NewStatusUpdate($incident, $statusUpdate));
                }
            }
        }
    }

    /**
     * Handle the StatusUpdate "updated" event.
     */
    public function updated(StatusUpdate $statusUpdate): void
    {
        //
    }

    /**
     * Handle the StatusUpdate "deleted" event.
     */
    public function deleted(StatusUpdate $statusUpdate): void
    {
        //
    }

    /**
     * Handle the StatusUpdate "restored" event.
     */
    public function restored(StatusUpdate $statusUpdate): void
    {
        //
    }

    /**
     * Handle the StatusUpdate "force deleted" event.
     */
    public function forceDeleted(StatusUpdate $statusUpdate): void
    {
        //
    }
}
