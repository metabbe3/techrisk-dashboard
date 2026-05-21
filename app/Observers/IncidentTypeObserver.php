<?php

namespace App\Observers;

use App\Models\IncidentType;

class IncidentTypeObserver extends CacheClearingObserver
{
    protected function cacheKey(): string
    {
        return 'incident_types';
    }

    /**
     * Handle the IncidentType "force deleted" event.
     */
    public function forceDeleted(IncidentType $incidentType): void
    {
        $this->clearCache();
    }
}
