<?php

namespace App\Observers;

use App\Models\Incident;
use App\Models\Label;
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

        // Auto-tagging
        $tags = [];
        if ($incident->root_cause) {
            $keywords = preg_split('/[\s,]+/', $incident->root_cause);
            foreach ($keywords as $keyword) {
                if (strlen($keyword) >= 4) {
                    $tags[] = strtolower($keyword);
                }
            }
        }
        if ($incident->incident_type) {
            $tags[] = strtolower($incident->incident_type);
        }

        $labelIds = [];
        foreach ($tags as $tagName) {
            $label = Label::firstOrCreate(['name' => $tagName]);
            $labelIds[] = $label->id;
        }
        $incident->labels()->syncWithoutDetaching($labelIds);

        // Calculate MTTR
        if ($incident->stop_bleeding_at) {
            $incident->mttr = $incident->incident_date->diffInMinutes($incident->stop_bleeding_at);
        }

        // Calculate MTBF
        $previousIncident = Incident::where('incident_date', '<', $incident->incident_date)
            ->orderBy('incident_date', 'desc')
            ->first();

        if ($previousIncident) {
            $incident->mtbf = $previousIncident->incident_date->diffInDays($incident->incident_date);
        }

        $incident->saveQuietly();

        // Recalculate MTBF for the next incident
        $nextIncident = Incident::where('incident_date', '>', $incident->incident_date)
            ->orderBy('incident_date', 'asc')
            ->first();

        if ($nextIncident) {
            $nextIncident->mtbf = $incident->incident_date->diffInDays($nextIncident->incident_date);
            $nextIncident->saveQuietly();
        }
    }

    /**
     * Handle the Incident "updated" event.
     */
    public function updated(Incident $incident): void
    {
        Cache::tags(['incidents'])->flush();

        if ($incident->isDirty('root_cause') || $incident->isDirty('incident_type')) {
            $newTags = [];
            if ($incident->root_cause) {
                $keywords = preg_split('/[\s,]+/', $incident->root_cause);
                foreach ($keywords as $keyword) {
                    if (strlen($keyword) >= 4) {
                        $newTags[] = strtolower($keyword);
                    }
                }
            }
            if ($incident->incident_type) {
                $newTags[] = strtolower($incident->incident_type);
            }

            $labelIds = [];
            foreach ($newTags as $tagName) {
                $label = Label::firstOrCreate(['name' => $tagName]);
                $labelIds[] = $label->id;
            }
            $incident->labels()->sync($labelIds);
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

            if ($previousIncident) {
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
                $nextIncident->mtbf = $incident->incident_date->diffInDays($nextIncident->incident_date);
                $nextIncident->saveQuietly();
            }
        }

        if ($incident->wasChanged()) {
            if ($incident->pic) {
                $incident->pic->notify(new IncidentUpdated($incident));
            }
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