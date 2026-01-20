<?php

// app/Models/Incident.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Contracts\Auditable;

class Incident extends Model implements Auditable
{
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'no',
        'title',
        'summary',
        'root_cause',
        'remark',
        'improvements',
        'timeline',
        'incident_date',
        'entry_date_tech_risk',
        'discovered_at',
        'stop_bleeding_at',
        'classification',
        'severity',
        'glitch_flag',
        'incident_type',
        'incident_source',
        'incident_category',
        'incident_type_id',
        'incident_status',
        'fund_status',
        'potential_fund_loss',
        'recovered_fund',
        'fund_loss',
        'loss_taken_by',
        'pic',
        'pic_id',
        'reported_by',
        'third_party_client',
        'evidence',
        'evidence_link',
        'risk_incident_form_cfm',
        'action_improvement_tracking',
        'goc_upload',
        'teams_upload',
        'doc_signed',
        'investigation_pic_status',
        'people_caused',
        'checker',
        'maker',
        'mttr',
        'mtbf',
    ];

    protected $casts = [
        'goc_upload' => 'boolean',
        'teams_upload' => 'boolean',
        'stop_bleeding_at' => 'datetime',
        'discovered_at' => 'datetime',
        'incident_date' => 'datetime',
        'entry_date_tech_risk' => 'date',
        'people_caused' => 'array',
    ];

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_id');
    }

    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class);
    }

    public function statusUpdates(): HasMany
    {
        return $this->hasMany(StatusUpdate::class)->latest();
    }

    public function latestStatusUpdate(): HasOne
    {
        return $this->hasOne(StatusUpdate::class)->latestOfMany();
    }

    public function investigationDocuments(): HasMany
    {
        return $this->hasMany(InvestigationDocument::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'incident_label');
    }

    public function actionImprovements(): HasMany
    {
        return $this->hasMany(ActionImprovement::class);
    }

    public function scopeIssues($query)
    {
        return $query->where('classification', 'Issue');
    }
}
