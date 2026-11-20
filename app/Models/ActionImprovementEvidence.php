<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A proof that an action improvement happened — an encrypted file
 * (photo/text/pdf) and/or a link (chat / Notion URL). NOT Auditable on
 * purpose: file/link events are audited on the parent Incident
 * (supporting-doc precedent), so auditing here too would double-count.
 *
 * Proof workflow (owner 2026-11-20): rows created by the Submit Proof action
 * carry `submitted_by`; approval stamps `approved_at`/`approved_by`.
 * submitted_by IS NULL = plain admin upload (Evidence tab) — never counts as
 * pending approval.
 */
class ActionImprovementEvidence extends Model
{
    protected $fillable = [
        'action_improvement_id',
        'file_path',
        'original_filename',
        'link_url',
        'submitted_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function actionImprovement(): BelongsTo
    {
        return $this->belongsTo(ActionImprovement::class);
    }

    public function evidenceKey(): HasOne
    {
        return $this->hasOne(ActionImprovementEvidenceKey::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Worker-submitted proof awaiting a trusted approval. */
    public function isPendingApproval(): bool
    {
        return filled($this->submitted_by) && blank($this->approved_at);
    }

    public function isLink(): bool
    {
        return blank($this->file_path) && filled($this->link_url);
    }
}
