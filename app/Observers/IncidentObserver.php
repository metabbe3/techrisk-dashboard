<?php

namespace App\Observers;

use App\Jobs\CalculateIncidentMetrics;
use App\Models\Incident;
use App\Notifications\AssignedAsPicNotification;
use App\Notifications\IncidentStatusChanged;
use App\Notifications\IncidentUpdated;
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

        // Notify PIC if assigned during creation
        if ($incident->pic_id && $incident->pic) {
            $incident->pic->notify(new AssignedAsPicNotification($incident));
        }
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

                $changes[$label] = [
                    'from' => $oldValue,
                    'to' => $newValue,
                ];
            }
        }

        // Handle PIC assignment change
        if ($incident->isDirty('pic_id') && $incident->pic_id && $pic) {
            $pic->notify(new AssignedAsPicNotification($incident));
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

        // Handle status change notification
        if ($incident->isDirty('incident_status')) {
            $oldStatus = $incident->getOriginal('incident_status');
            $newStatus = $incident->incident_status;

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
    }
}
