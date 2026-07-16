<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentSimilarIncident extends Model
{
    protected $fillable = [
        'incident_id',
        'similar_incident_id',
        'similarity',
        'match_type',
        'reasoning',
        'dimensions',
        'dismissed_at',
        'dismissed_by',
    ];

    protected $casts = [
        'similarity' => 'float',
        'dimensions' => 'array',
        'dismissed_at' => 'datetime',
    ];

    /**
     * The incident this similarity was detected for.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    /**
     * The incident flagged as similar.
     */
    public function similarIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'similar_incident_id');
    }

    /**
     * The admin who dismissed this match (null while active).
     */
    public function dismisser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    /**
     * Only currently-active (not dismissed) matches.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('dismissed_at');
    }

    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    /**
     * Serialize to the shape the similar-incidents UI expects. Requires the
     * similarIncident relation to be eager-loaded.
     */
    public function toCardArray(): array
    {
        $related = $this->similarIncident;

        return [
            'id' => $this->similar_incident_id,
            'row_id' => $this->id,
            'no' => $related?->no,
            'title' => $related?->title,
            'summary' => $related?->summary,
            'severity' => $related?->severity?->value,
            'incident_status' => $related?->incident_status?->value,
            'incident_date' => $related?->incident_date?->toDateString(),
            'similarity' => (float) $this->similarity,
            'match_type' => $this->match_type,
            'reason' => $this->reasoning,
            'dimensions' => $this->dimensions ?? [],
            'double_checked' => false,
        ];
    }
}
