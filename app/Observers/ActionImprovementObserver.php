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
        if ($actionImprovement->pic_email) {
            // Use queue for async email sending to improve performance
            foreach ($actionImprovement->pic_email as $email) {
                Mail::to($email)->queue(new ActionImprovementNotification($actionImprovement));
            }
        }
    }

    /**
     * Handle the ActionImprovement "updated" event.
     */
    public function updated(ActionImprovement $actionImprovement): void
    {
        if ($actionImprovement->isDirty('pic_email')) {
            if ($actionImprovement->pic_email) {
                // Use queue for async email sending to improve performance
                foreach ($actionImprovement->pic_email as $email) {
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
