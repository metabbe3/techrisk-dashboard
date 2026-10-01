<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Contracts\Auditable;

class Label extends Model implements Auditable
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    /**
     * Incidents tagged with this label are excluded from every MTBF/MTTR
     * calculation (owner rule 2026-10-01) while staying in all counts.
     * Hand-applied only — autoLabel() never attaches it from text.
     */
    public const OUTLIER = 'Outlier';

    protected $fillable = ['name'];

    public function incidents(): BelongsToMany
    {
        // ->using matters on BOTH sides: pivot events (the Outlier recalc
        // trigger) only fire through a relation that declares the class.
        return $this->belongsToMany(Incident::class, 'incident_label')
            ->using(IncidentLabel::class);
    }
}
