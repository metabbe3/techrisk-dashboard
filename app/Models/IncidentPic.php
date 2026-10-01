<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\AssignedAsPicNotification;
use App\Notifications\PicAssignedNotification;
use App\Services\Ai\RagService;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Custom pivot for incident_pic. Exists because PIC assignment became a
 * pivot sync (multi-PIC, PROJ-010), which fires NO Incident model event —
 * so assignment notifications, RAG re-index and AI-cache freshness all hook
 * the pivot itself. Mirrors the IncidentLabel precedent.
 *
 * sync() fires created/deleted only for users actually attached/detached —
 * re-saving unchanged PICs notifies nobody.
 */
class IncidentPic extends Pivot
{
    protected $table = 'incident_pic';

    protected static function booted(): void
    {
        static::created(function (self $pivot): void {
            $user = User::find($pivot->user_id);
            $incident = Incident::find($pivot->incident_id);

            if ($user && $incident) {
                // Don't email the actor their own assignment (mirrors the old
                // observer self-guard).
                $actor = auth()->user();

                if (! $actor || $actor->id !== $user->id) {
                    $user->notify(new AssignedAsPicNotification($incident));
                }

                self::notifyAdminsOfAssignment($incident, $user, $actor);
            }

            $pivot->refreshSearchAndCaches();
        });

        static::deleted(function (self $pivot): void {
            // No notification on PIC removal (product rule) — but search/AI
            // caches still depend on the PIC list.
            $pivot->refreshSearchAndCaches();
        });
    }

    /**
     * Pivot writes fire no Incident observer — re-index the incident for AI
     * search and drop the chat/dashboard caches here, exactly like the
     * IncidentLabel pivot does for Outlier.
     */
    private function refreshSearchAndCaches(): void
    {
        $incident = Incident::find($this->incident_id);

        if ($incident) {
            try {
                app(RagService::class)->indexIncident($incident);
            } catch (\Throwable) {
                // RAG indexing must never break an assignment save.
            }
        }

        try {
            app(\App\Services\Ai\ChatContextService::class)->clearDataCache();
        } catch (\Throwable) {
        }
    }

    /**
     * Moved from IncidentObserver::notifyAdminsOfPicAssignment — admins are
     * told who was assigned, minus the assignee and the acting user.
     */
    private static function notifyAdminsOfAssignment(Incident $incident, User $assignedPic, ?User $actor): void
    {
        $notified = [$assignedPic->id];

        if ($actor) {
            $notified[] = $actor->id;
        }

        $admins = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->get();

        foreach ($admins as $admin) {
            if (! in_array($admin->id, $notified)) {
                $admin->notify(new PicAssignedNotification($incident, $assignedPic));
                $notified[] = $admin->id;
            }
        }
    }
}
