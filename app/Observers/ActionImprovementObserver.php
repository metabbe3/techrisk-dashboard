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
            // Re-assignment (owner 2026-11-20): only the NEWLY-ADDED emails
            // get the assignment mail — existing assignees were notified when
            // they were assigned and a re-email on every PIC edit is noise.
            $added = array_values(array_diff(
                (array) $actionImprovement->pic_email,
                (array) $actionImprovement->getOriginal('pic_email'),
            ));

            $this->notifyAssignees($actionImprovement, $added);
        }
    }

    /**
     * Send notifications to assigned PICs via User::notify() to respect preferences.
     *
     * @param  array<int, string>|null  $emails  subset to notify (defaults to
     *                                           every assigned email — used by created())
     */
    private function notifyAssignees(ActionImprovement $actionImprovement, ?array $emails = null): void
    {
        // Assignment mail is for open work only — rows created or PIC-edited
        // as done/draft/cancelled/tbd never chase (owner 2026-11-19).
        if (! $actionImprovement->isOpenWork()) {
            return;
        }

        $emails ??= $actionImprovement->pic_email;

        if (! $emails || ! is_array($emails)) {
            return;
        }

        $notified = [];

        foreach ($emails as $email) {
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
