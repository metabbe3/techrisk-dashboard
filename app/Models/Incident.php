<?php

// app/Models/Incident.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Contracts\Auditable;

class Incident extends Model implements Auditable
{
    use HasFactory;
    use \OwenIt\Auditing\Auditing;

    protected $appends = ['mtbf_display'];

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
        'mtbf_completed',
        'mtbf_recovered',
        'mtbf_p4',
        'mtbf_non_tech',
        'mtbf_fund_loss',
        'mtbf_non_fund_loss',
        'mtbf_potential_recovery',
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

    /**
     * Get formatted MTTR with appropriate unit.
     * - Fund loss incidents: negative value stored as days, display as "X days"
     * - Regular incidents: positive value stored as minutes, display as "X mins" or "Xh Xm"
     */
    public function getMttrFormattedAttribute(): string
    {
        if ($this->mttr === null) {
            return '-';
        }

        if ($this->mttr < 0) {
            // Fund loss incident - stored as negative days
            $days = abs($this->mttr);
            if ($days > 36500) { // More than 100 years
                return 'N/A';
            }
            return $days . ' day' . ($days > 1 ? 's' : '');
        }

        // Regular incident - stored as minutes
        $minutes = $this->mttr;

        if ($minutes > 52560000) { // More than 100 years in minutes
            return 'N/A';
        }

        if ($minutes < 60) {
            return $minutes . ' min' . ($minutes > 1 ? 's' : '');
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($hours >= 24) {
            $days = floor($hours / 24);
            $hours = $hours % 24;
            return "{$days}d {$hours}h {$mins}m";
        }

        return "{$hours}h {$mins}m";
    }

    /**
     * Get the appropriate MTBF value based on incident category.
     * This dynamically returns the correct MTBF column based on which category
     * the incident belongs to, allowing the frontend to display "MTBF" with the
     * correct value for each filtered view.
     */
    public function getMtbfDisplayAttribute(): int
    {
        // Priority order for determining which MTBF to show
        // This matches the filter tabs in the frontend

        // Check if this is a recovered case (has recovered_fund > 0)
        if ($this->recovered_fund > 0) {
            return $this->mtbf_recovered ?? $this->mtbf ?? 0;
        }

        // Check if this is a completed case
        if ($this->incident_status === 'Completed') {
            return $this->mtbf_completed ?? $this->mtbf ?? 0;
        }

        // Check if this is P4
        if ($this->severity === 'P4') {
            return $this->mtbf_p4 ?? $this->mtbf ?? 0;
        }

        // Check if this is non-tech
        if ($this->incident_type === 'Non-tech') {
            return $this->mtbf_non_tech ?? $this->mtbf ?? 0;
        }

        // Check fund status
        if ($this->fund_status === 'Confirmed loss') {
            return $this->mtbf_fund_loss ?? $this->mtbf ?? 0;
        }

        if ($this->fund_status === 'Non fundLoss') {
            return $this->mtbf_non_fund_loss ?? $this->mtbf ?? 0;
        }

        if ($this->fund_status === 'Potential recovery') {
            return $this->mtbf_potential_recovery ?? $this->mtbf ?? 0;
        }

        // Default to overall MTBF
        return $this->mtbf ?? 0;
    }

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

    /**
     * Check if this incident has fund loss.
     * Fund loss incidents are excluded from MTTR calculation
     * as they involve legal processes that take much longer.
     */
    public function hasFundLoss(): bool
    {
        return $this->fund_loss !== null && $this->fund_loss > 0;
    }

    /**
     * Check if MTTR should be calculated by days (based on fund_status).
     * Returns true for "Confirmed loss" or "Potential recovery".
     * Returns false for "Non fundLoss" (calculates by minutes).
     */
    public function shouldCalculateMttrByDays(): bool
    {
        return in_array($this->fund_status, ['Confirmed loss', 'Potential recovery']);
    }
}
