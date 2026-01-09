<?php

namespace App\Observers;

use App\Models\Incident;
use App\Models\Label;
use App\Notifications\AssignedAsPicNotification;
use App\Notifications\IncidentUpdated;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class IncidentObserver
{
    /**
     * Handle the Incident "created" event.
     */
    public function created(Incident $incident): void
    {
        Cache::tags(['incidents'])->flush();

        $this->autoLabel($incident);

        // Calculate MTTR
        if ($incident->stop_bleeding_at) {
            $incident->mttr = $incident->incident_date->diffInMinutes($incident->stop_bleeding_at);
        }

        // Calculate MTBF
        $previousIncident = Incident::where('incident_date', '<', $incident->incident_date)
            ->orderBy('incident_date', 'desc')
            ->first();

        if ($previousIncident && $previousIncident->incident_date->year == $incident->incident_date->year) {
            $incident->mtbf = $previousIncident->incident_date->diffInDays($incident->incident_date);
        } else {
            $incident->mtbf = null;
        }

        $incident->saveQuietly();

        // Recalculate MTBF for the next incident
        $nextIncident = Incident::where('incident_date', '>', $incident->incident_date)
            ->orderBy('incident_date', 'asc')
            ->first();

        if ($nextIncident) {
            if ($incident->incident_date->year == $nextIncident->incident_date->year) {
                $nextIncident->mtbf = $incident->incident_date->diffInDays($nextIncident->incident_date);
            } else {
                $nextIncident->mtbf = null;
            }
            $nextIncident->saveQuietly();
        }
    }

    /**
     * Handle the Incident "updated" event.
     */
    public function updated(Incident $incident): void
    {
        if ($incident->isDirty('pic_id') && $incident->pic_id) {
            $incident->pic->notify(new AssignedAsPicNotification($incident));
        }

        Cache::tags(['incidents'])->flush();

        if ($incident->isDirty('summary') || $incident->isDirty('root_cause')) {
            $this->autoLabel($incident);
        }

        if ($incident->isDirty('incident_date') || $incident->isDirty('stop_bleeding_at')) {
            // Recalculate MTTR
            if ($incident->stop_bleeding_at) {
                $incident->mttr = $incident->incident_date->diffInMinutes($incident->stop_bleeding_at);
            } else {
                $incident->mttr = null;
            }

            // Recalculate MTBF for this incident
            $previousIncident = Incident::where('incident_date', '<', $incident->incident_date)
                ->where('id', '!=', $incident->id)
                ->orderBy('incident_date', 'desc')
                ->first();

            if ($previousIncident && $previousIncident->incident_date->year == $incident->incident_date->year) {
                $incident->mtbf = $previousIncident->incident_date->diffInDays($incident->incident_date);
            } else {
                $incident->mtbf = null;
            }

            $incident->saveQuietly();

            // Recalculate MTBF for the next incident
            $nextIncident = Incident::where('incident_date', '>', $incident->incident_date)
                ->where('id', '!=', $incident->id)
                ->orderBy('incident_date', 'asc')
                ->first();

            if ($nextIncident) {
                if ($incident->incident_date->year == $nextIncident->incident_date->year) {
                    $nextIncident->mtbf = $incident->incident_date->diffInDays($nextIncident->incident_date);
                } else {
                    $nextIncident->mtbf = null;
                }
                $nextIncident->saveQuietly();
            }
        }

        if ($incident->wasChanged()) {
            if ($incident->pic) {
                // Get the user who made the change (authenticated user)
                $currentUser = auth()->user();

                // Only send notification if the PIC is not the current user
                if ($currentUser && $currentUser->id !== $incident->pic_id) {
                    $incident->pic->notify(new IncidentUpdated($incident));
                }
            }
        }
    }

    private function autoLabel(Incident $incident): void
    {
        // 1. Get all available labels from cache (or DB if not in cache)
        $allLabels = Cache::remember('labels', 3600, function () { // Cache for 60 minutes
            return Label::all();
        });

        // 2. Create a text block from summary and root_cause
        $textBlock = strtolower($incident->summary . ' ' . $incident->root_cause);

        // 3. Find matched labels
        $matchedLabelIds = [];
        foreach ($allLabels as $label) {
            // Use word boundary regex for whole word matching
            if (preg_match("/\b" . preg_quote(strtolower($label->name), '/') . "\b/", $textBlock)) {
                $matchedLabelIds[] = $label->id;
            }
        }

        // 4. Sync labels without detaching existing ones
        if (!empty($matchedLabelIds)) {
            $incident->labels()->syncWithoutDetaching($matchedLabelIds);
        }
    }


    /**
     * Handle the Incident "deleted" event.
     */
    public function deleted(Incident $incident): void
    {
        Cache::tags(['incidents'])->flush();
    }

    /**
     * Handle the Incident "restored" event.
     */
    public function restored(Incident $incident): void
    {
        Cache::tags(['incidents'])->flush();
    }

    /**
     * Handle the Incident "force deleted" event.
     */
    public function forceDeleted(Incident $incident): void
    {
        Cache::tags(['incidents'])->flush();
    }
}