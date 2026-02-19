<?php

namespace App\Observers;

use App\Mail\ActionImprovementNotification;
use App\Models\ActionImprovement;
use Illuminate\Support\Facades\Mail;

class ActionImprovementObserver
{
    /**
     * Handle the ActionImprovement "created" event.
     */
    public function created(ActionImprovement $actionImprovement): void
    {
        $this->sendActionImprovementNotifications($actionImprovement);
    }

    /**
     * Handle the ActionImprovement "updated" event.
     */
    public function updated(ActionImprovement $actionImprovement): void
    {
        if ($actionImprovement->isDirty('pic_email')) {
            $this->sendActionImprovementNotifications($actionImprovement);
        }
    }

    /**
     * Send notifications to PIC emails for an action improvement.
     */
    private function sendActionImprovementNotifications(ActionImprovement $actionImprovement): void
    {
        if ($actionImprovement->pic_email && is_array($actionImprovement->pic_email)) {
            foreach ($actionImprovement->pic_email as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Mail::to($email)->queue(new ActionImprovementNotification($actionImprovement));
                }
            }
        }
    }

    /**
     * Handle the ActionImprovement "deleted" event.
     */
    public function deleted(ActionImprovement $actionImprovement): void
    {
        //
    }

    /**
     * Handle the ActionImprovement "restored" event.
     */
    public function restored(ActionImprovement $actionImprovement): void
    {
        //
    }

    /**
     * Handle the ActionImprovement "force deleted" event.
     */
    public function forceDeleted(ActionImprovement $actionImprovement): void
    {
        //
    }
}
