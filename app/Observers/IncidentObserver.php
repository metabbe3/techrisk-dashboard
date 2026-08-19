<?php

namespace App\Observers;

use App\Enums\Severity;
use App\Events\IncidentCreatedEvent;
use App\Events\IncidentEscalatedEvent;
use App\Jobs\CalculateIncidentMetrics;
use App\Jobs\DetectRecurrenceJob;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\AssignedAsPicNotification;
use App\Notifications\IncidentStatusChanged;
use App\Notifications\IncidentUpdated;
use App\Notifications\NewCriticalIncident;
use App\Notifications\PicAssignedNotification;
use Carbon\Carbon;

class IncidentObserver
{
    /**
     * Handle the Incident "created" event.
     */
    public function created(Incident $incident): void
    {
        // Dispatch metrics calculation to queue for better performance
        dispatch(new CalculateIncidentMetrics(
            $incident,
            shouldAutoLabel: true,
            shouldUpdateAdjacent: true
        ));

        // Dispatch recurrence detection (delayed to allow categories/labels to be saved)
        DetectRecurrenceJob::dispatch($incident)->delay(now()->addSeconds(30));

        // Notify PIC if assigned during creation
        if ($incident->pic_id && $incident->pic) {
            $incident->pic->notify(new AssignedAsPicNotification($incident));

            $this->notifyAdminsOfPicAssignment($incident, $incident->pic);
        }

        // Notify admins and team leads for P1/P2 incidents
        if (in_array($incident->severity, [Severity::P1, Severity::P2])) {
            $this->notifyCriticalIncident($incident);
        }

        // Fire event for AI proactive analysis
        event(new IncidentCreatedEvent($incident));

        $this->clearAiContextCache();
    }

    /**
     * Handle the Incident "updated" event.
     */
    public function updated(Incident $incident): void
    {
        $pic = $incident->pic;
        $currentUser = auth()->user();

        // Track changes for notification
        $changes = [];
        $notifiableFields = [
            'title' => 'Title',
            'severity' => 'Severity',
            'incident_status' => 'Status',
            'incident_type' => 'Type',
            'incident_source' => 'Source',
            'summary' => 'Summary',
            'root_cause' => 'Root Cause',
            'fund_loss' => 'Fund Loss',
        ];

        foreach ($notifiableFields as $field => $label) {
            if ($incident->isDirty($field)) {
                $oldValue = $incident->getOriginal($field);
                $newValue = $incident->$field;

                if ($oldValue instanceof Carbon) {
                    $oldValue = $oldValue->format('Y-m-d H:i');
                }
                if ($newValue instanceof Carbon) {
                    $newValue = $newValue->format('Y-m-d H:i');
                }
                if ($newValue instanceof \BackedEnum) {
                    $newValue = $newValue->value;
                }
                if ($oldValue instanceof \BackedEnum) {
                    $oldValue = $oldValue->value;
                }

                $changes[$label] = [
                    'from' => $oldValue,
                    'to' => $newValue,
                ];
            }
        }

        // Handle PIC assignment change
        if ($incident->isDirty('pic_id') && $incident->pic_id && $pic) {
            $pic->notify(new AssignedAsPicNotification($incident));

            $this->notifyAdminsOfPicAssignment($incident, $pic);
        }

        // Determine if metrics recalculation is needed
        $needsRecalculation = $incident->isDirty('incident_date') || $incident->isDirty('stop_bleeding_at');
        $needsCategoryRecalculation = $incident->isDirty('incident_status') || $incident->isDirty('severity') ||
            $incident->isDirty('incident_type') || $incident->isDirty('fund_status') ||
            $incident->isDirty('recovered_fund') || $incident->isDirty('classification');
        $needsAutoLabel = $incident->isDirty('summary') || $incident->isDirty('root_cause');

        // Dispatch metrics calculation to queue if needed
        if ($needsRecalculation || $needsCategoryRecalculation || $needsAutoLabel) {
            $previousClassification = $incident->isDirty('classification')
                ? $incident->getOriginal('classification')
                : null;

            dispatch(new CalculateIncidentMetrics(
                $incident,
                shouldAutoLabel: $needsAutoLabel,
                shouldUpdateAdjacent: $needsRecalculation || $incident->isDirty('classification'),
                previousClassification: $previousClassification
            ));
        }

        // Dispatch recurrence detection when categories change and not yet analyzed
        if ($incident->wasChanged(['root_cause_category', 'business_category', 'responsible_team'])
            && $incident->recurrence_data === null) {
            DetectRecurrenceJob::dispatch($incident);
        }

        // Handle status change notification
        if ($incident->isDirty('incident_status')) {
            $oldStatus = $incident->getOriginal('incident_status')?->value;
            $newStatus = $incident->incident_status->value;

            if ($pic && $oldStatus && $newStatus) {
                if (! $currentUser || $currentUser->id !== $incident->pic_id) {
                    $pic->notify(new IncidentStatusChanged($incident, $oldStatus, $newStatus));
                }
            }
        }

        // Send general update notification if there are meaningful changes
        if ($incident->wasChanged() && ! empty($changes) && $pic) {
            if ($currentUser && $currentUser->id !== $incident->pic_id) {
                $pic->notify(new IncidentUpdated($incident, $changes));
            }
        }

        // Fire event for severity escalation to P1/P2
        if ($incident->isDirty('severity') && in_array($incident->severity, [Severity::P1, Severity::P2])) {
            event(new IncidentEscalatedEvent($incident, $incident->getOriginal('severity')));
        }

        $this->clearAiContextCache();
    }

    /**
     * AI chat context caches 5-minute aggregates; without this the chat
     * answers with stale counts right after an incident is added/edited.
     */
    private function clearAiContextCache(): void
    {
        app(\App\Services\Ai\ChatContextService::class)->clearDataCache();
    }

    /**
     * Notify admins and team leads about a new critical incident.
     */
    private function notifyCriticalIncident(Incident $incident): void
    {
        $currentUser = auth()->user();
        $notified = [$incident->pic_id];

        if ($currentUser) {
            $notified[] = $currentUser->id;
        }

        $recipients = User::whereHas('roles', function ($query) {
            $query->whereIn('name', ['admin', 'team-lead']);
        })->get();

        foreach ($recipients as $user) {
            if (! in_array($user->id, $notified)) {
                $user->notify(new NewCriticalIncident($incident));
                $notified[] = $user->id;
            }
        }
    }

    /**
     * Notify admins when a team member is assigned as PIC.
     */
    private function notifyAdminsOfPicAssignment(Incident $incident, User $assignedPic): void
    {
        $currentUser = auth()->user();
        $notified = [$assignedPic->id];

        if ($currentUser) {
            $notified[] = $currentUser->id;
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
