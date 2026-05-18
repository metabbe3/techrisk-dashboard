<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RagDocument extends Model
{
    protected $fillable = [
        'incident_id',
        'incident_no',
        'severity',
        'classification',
        'incident_status',
        'incident_type',
        'incident_date',
        'fund_status',
        'fund_loss',
        'potential_fund_loss',
        'pic_id',
        'business_category',
        'root_cause_category',
        'responsible_team',
        'label_names',
        'searchable_content',
        'context_content',
        'indexed_at',
    ];

    protected $casts = [
        'incident_date' => 'date',
        'fund_loss' => 'decimal:2',
        'potential_fund_loss' => 'decimal:2',
        'business_category' => 'array',
        'root_cause_category' => 'array',
        'responsible_team' => 'array',
        'label_names' => 'array',
        'indexed_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_id');
    }

    public function scopeBySeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('incident_status', $status);
    }

    public function scopeByClassification($query, $classification)
    {
        return $query->where('classification', $classification);
    }

    public function scopeDateRange($query, $from, $to)
    {
        return $query->whereBetween('incident_date', [$from, $to]);
    }
}
