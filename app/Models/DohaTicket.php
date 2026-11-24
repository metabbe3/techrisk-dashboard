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
}
