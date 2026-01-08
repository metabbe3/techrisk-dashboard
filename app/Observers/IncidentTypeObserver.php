<?php

namespace App\Observers;

use App\Models\IncidentType;
use Illuminate\Support\Facades\Cache;

class IncidentTypeObserver
{
    /**
     * Handle the IncidentType "created" event.
     */
    public function created(IncidentType $incidentType): void
    {
        Cache::forget('incident_types');
    }

    /**
     * Handle the IncidentType "updated" event.
     */
    public function updated(IncidentType $incidentType): void
    {
        Cache::forget('incident_types');
    }

    /**
     * Handle the IncidentType "deleted" event.
     */
    public function deleted(IncidentType $incidentType): void
    {
        Cache::forget('incident_types');
    }

    /**
     * Handle the IncidentType "restored" event.
     */
    public function restored(IncidentType $incidentType): void
    {
        Cache::forget('incident_types');
    }

    /**
     * Handle the IncidentType "force deleted" event.
     */
    public function forceDeleted(IncidentType $incidentType): void
    {
        Cache::forget('incident_types');
    }
}
