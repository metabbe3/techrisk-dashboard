<?php

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

        // Notify the PIC if they are not the one who created the status update
        if ($incident && $incident->pic_id) {
            $currentUser = Auth::user();

            // Only notify if:
            // 1. There is a current user
            // 2. The PIC is different from the current user
            if (!$currentUser || $currentUser->id !== $incident->pic_id) {
                $incident->pic->notify(new NewStatusUpdate($incident, $statusUpdate));
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
