<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActionImprovement;
use App\Models\User;
use App\Notifications\ActionImprovementAssigned;

class ActionImprovementObserver
{
    /**
     * Normalize pic_email tags ("Name <email>" suggestions from the directory
     * become bare emails) and upsert every address into the user directory
     * (owner request 2026-10-13) before anything else reads them.
     *
     * Closeout stamping (PROJ-014): transition INTO done stamps completed_at
     * + completed_by; transition back out clears them. Pre-done rows keep
     * whatever they have (re-saving a done row doesn't re-stamp).
     */
    public function saving(ActionImprovement $actionImprovement): void
    {
        if (is_array($actionImprovement->pic_email) && $actionImprovement->pic_email !== []) {
            $actionImprovement->pic_email = User::upsertFromPicEmails($actionImprovement->pic_email);
        }

        $wasDone = ($actionImprovement->getOriginal('status') ?? null) === ActionImprovement::STATUS_DONE;
        $isDone = $actionImprovement->status === ActionImprovement::STATUS_DONE;

        if ($isDone && ! $wasDone) {
            $actionImprovement->completed_at ??= now();
            $actionImprovement->completed_by ??= auth()->id();
        } elseif ($wasDone && ! $isDone) {
            $actionImprovement->completed_at = null;
            $actionImprovement->completed_by = null;
        }
    }

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
        // Assignment mail is for open work only — rows created or PIC-edited
        // as done/draft/cancelled/tbd never chase (owner 2026-11-19).
        if (! $actionImprovement->isOpenWork()) {
            return;
        }

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
