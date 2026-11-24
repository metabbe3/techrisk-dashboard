<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read-only mirror of a DOHA fund-loss ticket (DOHA API v1). Sync-owned —
 * written only by DohaSyncService. Free-text fields (domain, status,
 * priority) are NOT validated against a fixed list per the DOHA contract.
 *
 * Deliberately NOT wired into any incident metric surface; incident_id is
 * the ONLY join to Incidents (created/linked manually by an admin).
 */
class DohaTicket extends Model
{
    use HasFactory;
    use SerializesDatesInAppTimezone;

    protected $fillable = [
        'doha_id',
        'ref',
        'title',
        'bank',
        'domain',
        'team',
        'category',
        'service_impact',
        'root_cause',
        'business_type',
        'total_transaction',
        'potential_loss',
        'recovered',
        'priority',
        'status',
        'transaction_date',
        'transaction_time',
        'close_date',
        'recovery_action',
        'follow_up_action',
        'created_by',
        'created_by_edit',
        'doha_created_at',
        'doha_updated_at',
        'incident_id',
    ];

    protected $casts = [
        'total_transaction' => 'decimal:2',
        'potential_loss' => 'decimal:2',
        'recovered' => 'decimal:2',
        'transaction_date' => 'date',
        'close_date' => 'date',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(DohaTicketChange::class)->latest('id');
    }

    /** DOHA's naive UTC createdAt string, as app-timezone Carbon (WIB). */
    public function getDohaCreatedAtWibAttribute(): ?Carbon
    {
        return $this->dohaTimestampToWib($this->doha_created_at);
    }

    /** DOHA's naive UTC updatedAt string, as app-timezone Carbon (WIB). */
    public function getDohaUpdatedAtWibAttribute(): ?Carbon
    {
        return $this->dohaTimestampToWib($this->doha_updated_at);
    }

    private function dohaTimestampToWib(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d H:i:s', $value, 'UTC')
            ->tz(config('app.timezone', 'Asia/Jakarta'));
    }

    /**
     * The admin-completed defaults for "Create Incident from this ticket"
     * (owner 2026-11-24 mapping, replaces the 2026-11-23 cut). fund_status /
     * incident_type / incident_source are deliberately NOT prefilled — the
     * DOHA values stay visible on the ticket page and the admin picks the
     * portal ones.
     */
    public function incidentPrefillAttributes(): array
    {
        $severity = match (strtolower((string) $this->priority)) {
            'critical' => 'P1',
            'high' => 'P2',
            'medium' => 'P3',
            'low' => 'P4',
            default => null, // free text outside the usual scale — admin picks
        };

        // Option-select fields are never filled with free text — only when
        // the DOHA value ci-matches an existing option.
        $teamOption = $this->matchCategory($this->team, Category::TYPE_RESPONSIBLE_TEAM);
        $businessOption = $this->matchCategory($this->business_type, Category::TYPE_BUSINESS_CATEGORY);

        $incidentDate = $this->transaction_date !== null
            ? $this->transaction_date->format('Y-m-d').' '.($this->transaction_time ?? '00:00').':00'
            : null;

        $createdWib = $this->doha_created_at_wib;

        // Remarks collects the three provenance blocks (owner 2026-11-24).
        $remarkParts = [];
        if (filled($this->recovery_action)) {
            $remarkParts[] = "Recovery action:\n".$this->recovery_action;
        }
        if (filled($this->follow_up_action)) {
            $remarkParts[] = "Follow-up action:\n".$this->follow_up_action;
        }
        if (filled($this->created_by) || filled($this->created_by_edit)) {
            $remarkParts[] = 'Created by '.($this->created_by ?? '—')
                .', last edited by '.($this->created_by_edit ?? '—');
        }

        return [
            'title' => $this->title,
            'summary' => trim("Imported from DOHA ticket {$this->ref}.\n\n".$this->service_impact),
            'root_cause' => $this->root_cause,
            'remark' => $remarkParts === [] ? null : implode("\n\n", $remarkParts),
            'potential_fund_loss' => $this->potential_loss,
            'recovered_fund' => $this->recovered,
            'severity' => $severity,
            'classification' => 'Incident', // owner: all fund-loss cases are incidents
            'responsible_team' => $teamOption !== null ? [$teamOption] : [],
            'business_category' => $businessOption !== null ? [$businessOption] : [],
            'discovered_at' => $createdWib?->format('Y-m-d H:i:s'),
            'entry_date_tech_risk' => $createdWib !== null
                ? $createdWib->format('Y-m-d')
                : now()->format('Y-m-d'),
            'incident_date' => $incidentDate,
        ];
    }

    private function matchCategory(?string $value, string $type): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Category::query()
            ->where('type', $type)
            ->whereRaw('LOWER(name) = ?', [strtolower($value)])
            ->value('name');
    }
}
