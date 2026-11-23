<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestigationFormRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'incident_id',
        'team',
        'expires_at',
        'status',
        'created_by',
        'answers',
        'draft_updated_by_email',
        'draft_updated_at',
        'extraction_status',
        'extraction_started_at',
        'extraction_result',
        'submitter_email',
        'submitter_name',
        'consented_at',
        'submitter_ip',
        'submitted_at',
        'docx_document_id',
        'pdf_document_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'answers' => 'array',
        'draft_updated_at' => 'datetime',
        'extraction_started_at' => 'datetime',
        'extraction_result' => 'array',
        'consented_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    // Millisecond write format — the intake's concurrency guard compares
    // draft_updated_at at sub-second resolution ('U.u'). The cast's format
    // is ignored on write; HasAttributes::fromDateTime uses $dateFormat.
    protected $dateFormat = 'Y-m-d H:i:s.v';

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(InvestigationFormRecipient::class, 'request_id');
    }

    public function docxDocument(): BelongsTo
    {
        return $this->belongsTo(InvestigationDocument::class, 'docx_document_id');
    }

    public function pdfDocument(): BelongsTo
    {
        return $this->belongsTo(InvestigationDocument::class, 'pdf_document_id');
    }

    /** The request itself is open: pending and inside its expiry window. */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /** A recipient link works only when the request is open (one submit/revoke/expiry kills all links). */
    public function linkIsValid(): bool
    {
        return $this->isOpen();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)->where('expires_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /** Derived request state shared by UI and API: submitted / revoked / expired / pending. */
    public function state(): string
    {
        return match (true) {
            $this->status === self::STATUS_SUBMITTED => 'submitted',
            $this->status === self::STATUS_REVOKED => 'revoked',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }
}
