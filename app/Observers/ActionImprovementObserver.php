<?php

namespace App\Observers;

use App\Models\ActionImprovement;
use App\Mail\ActionImprovementNotification;
use Illuminate\Support\Facades\Mail;

class ActionImprovementObserver
{
    /**
     * Handle the ActionImprovement "created" event.
     */
    public function created(ActionImprovement $actionImprovement): void
    {
        if ($actionImprovement->pic_email) {
            foreach ($actionImprovement->pic_email as $email) {
                Mail::to($email)->send(new ActionImprovementNotification($actionImprovement));
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
                foreach ($actionImprovement->pic_email as $email) {
                    Mail::to($email)->send(new ActionImprovementNotification($actionImprovement));
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
