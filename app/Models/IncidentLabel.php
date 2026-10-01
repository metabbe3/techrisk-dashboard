<?php

declare(strict_types=1);

namespace App\Models;

use App\Jobs\CalculateIncidentMetrics;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Custom pivot for incident_label. Exists for one reason: Filament persists
 * label edits as a pivot sync, which fires NO Incident model event — so the
 * Outlier rule's freshness (recalc + dashboard_cache_version bump when a
 * metric-defining label changes) must hook the pivot itself.
 */
class IncidentLabel extends Pivot
{
    protected $table = 'incident_label';

    public $timestamps = false; // the pivot table has no timestamp columns

    protected static function booted(): void
    {
        static::created(fn (self $pivot) => $pivot->recalculateIfOutlier());
        static::deleted(fn (self $pivot) => $pivot->recalculateIfOutlier());
    }

    private function recalculateIfOutlier(): void
    {
        if (Label::whereKey($this->label_id)->value('name') !== Label::OUTLIER) {
            return;
        }

        $incident = Incident::find($this->incident_id);

        if ($incident) {
            dispatch(new CalculateIncidentMetrics($incident));
        }
    }
}
