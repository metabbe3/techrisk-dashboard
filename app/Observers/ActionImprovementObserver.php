<?php

namespace App\Observers;

use App\Models\ActionImprovement;
use App\Models\User;
use App\Notifications\ActionImprovementAssigned;

class ActionImprovementObserver
{
    /**
     * Handle the ActionImprovement "created" event.
     */
    public function created(ActionImprovement $actionImprovement): void
    {
        $this->notifyAssignees($actionImprovement);
    }

    /**
     * Handle the ActionImprovement "updated" event.
     */
    public function updated(ActionImprovement $actionImprovement): void
    {
        if ($actionImprovement->isDirty('pic_email')) {
            $this->notifyAssignees($actionImprovement);
        }
    }

    /**
     * Send notifications to assigned PICs via User::notify() to respect preferences.
     */
    private function notifyAssignees(ActionImprovement $actionImprovement): void
    {
        if (! $actionImprovement->pic_email || ! is_array($actionImprovement->pic_email)) {
            return;
        }

        $notified = [];

        foreach ($actionImprovement->pic_email as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $user = User::where('email', $email)->first();
            if ($user && ! in_array($user->id, $notified)) {
                $user->notify(new ActionImprovementAssigned($actionImprovement));
                $notified[] = $user->id;
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
