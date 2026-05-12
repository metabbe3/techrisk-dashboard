<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Incident extends Model implements Auditable
{
    use AuditableTrait;
    use HasFactory;

    private const MTBF_TAB_COLUMN_MAP = [
        'All Cases' => 'mtbf',
        'Fund Loss' => 'mtbf_fund_loss',
        'Potential Recovery' => 'mtbf_potential_recovery',
        'Fully Recovered' => 'mtbf_fully_recovered',
        'Non Tech Loss' => 'mtbf_non_tech_loss',
        'Non Fund Loss' => 'mtbf_non_fund_loss',
        'Non Incident' => 'mtbf_non_incident',
        'Completed Cases' => 'mtbf_completed',
        'Recovered Cases' => 'mtbf_recovered',
        'P4 Incidents' => 'mtbf_p4',
        'Non-Tech Incidents' => 'mtbf_non_tech',
    ];

    protected $appends = ['mtbf_display'];

    protected $fillable = [
        'no',
        'title',
        'summary',
        'root_cause',
        'remark',
        'ai_enhanced_fields',
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
        'business_category',
        'root_cause_category',
        'responsible_team',
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
        'mtbf_fully_recovered',
        'mtbf_non_tech_loss',
        'mtbf_non_incident',
        'mtbf_all',
        'recurrence_data',
    ];

    protected $casts = [
        'goc_upload' => 'boolean',
        'teams_upload' => 'boolean',
        'doc_signed' => 'boolean',
        'risk_incident_form_cfm' => 'boolean',
        'glitch_flag' => 'boolean',
        'stop_bleeding_at' => 'datetime',
        'discovered_at' => 'datetime',
        'incident_date' => 'datetime',
        'entry_date_tech_risk' => 'date',
        'business_category' => 'array',
        'root_cause_category' => 'array',
        'responsible_team' => 'array',
        'ai_enhanced_fields' => 'array',
        'potential_fund_loss' => 'decimal:2',
        'recovered_fund' => 'decimal:2',
        'fund_loss' => 'decimal:2',
        'mttr' => 'decimal:2',
        'mtbf' => 'decimal:2',
        'mtbf_completed' => 'decimal:2',
        'mtbf_recovered' => 'decimal:2',
        'mtbf_p4' => 'decimal:2',
        'mtbf_non_tech' => 'decimal:2',
        'mtbf_fund_loss' => 'decimal:2',
        'mtbf_non_fund_loss' => 'decimal:2',
        'mtbf_potential_recovery' => 'decimal:2',
        'mtbf_fully_recovered' => 'decimal:2',
        'mtbf_non_tech_loss' => 'decimal:2',
        'mtbf_non_incident' => 'decimal:2',
        'mtbf_all' => 'decimal:2',
        'recurrence_data' => 'array',
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
            $days = abs((float) $this->mttr);
            if ($days > 36500) { // More than 100 years
                return 'N/A';
            }

            return $days.' day'.($days > 1 ? 's' : '');
        }

        // Regular incident - stored as minutes
        $minutes = (float) $this->mttr;

        if ($minutes > 52560000) { // More than 100 years in minutes
            return 'N/A';
        }

        if ($minutes < 60) {
            return $minutes.' min'.($minutes > 1 ? 's' : '');
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
     * Get MTBF value for a specific tab.
     */
    public function getMtbfForTab(string $tab): int
    {
        $column = self::MTBF_TAB_COLUMN_MAP[$tab] ?? 'mtbf';

        return (int) ($this->getAttribute($column) ?? 0);
    }

    /**
     * Backwards-compatible accessor: defaults to base MTBF.
     * Prefer getMtbfForTab() in Filament table columns.
     */
    public function getMtbfDisplayAttribute(): int
    {
        return (int) ($this->getAttribute('mtbf') ?? 0);
    }

    public static function generateNo(string $prefix, int $maxAttempts = 10): string
    {
        $baseId = date('Ymd').'_'.$prefix.'_';

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $candidate = $baseId.random_int(1000, 9999);
            if (! self::where('no', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $baseId.random_int(10000, 99999);
    }

    public function changeClassification(string $classification): void
    {
        $prefix = $classification === 'Incident' ? 'IN' : 'IS';
        $this->update([
            'classification' => $classification,
            'no' => self::generateNo($prefix),
        ]);
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

    public function warRoomSessions(): HasMany
    {
        return $this->hasMany(WarRoomSession::class);
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
        return in_array($this->fund_status, ['Confirmed loss', 'Potential recovery', 'Fully recovered', 'Non Tech Loss']);
    }
}
