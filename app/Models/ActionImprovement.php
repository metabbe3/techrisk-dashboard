<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class ActionImprovement extends Model implements Auditable
{
    use \App\Models\Concerns\SerializesDatesInAppTimezone;
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    /**
     * Status lifecycle: draft (AI-suggested) → pending → on_progress →
     * done | cancelled. 'on_progress' (owner 2026-11-17) is accepted AND
     * started, not finished — open work that rides every pending-gated
     * surface (reminders, escalation, overdue tiles, KPI, AI context).
     * 'tbd' ("TBD / to be discussed") is a parking state like
     * draft/cancelled: no decision yet on whether/how to do it — excluded
     * from reminders and every pending-gated metric. Distinct from
     * due_date = null ("TBD" date).
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ON_PROGRESS = 'on_progress';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_TBD = 'tbd';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_ON_PROGRESS, self::STATUS_DONE, self::STATUS_CANCELLED, self::STATUS_TBD];

    /**
     * Open work (Rule A, owner 2026-11-17): pending + on_progress ride every
     * pending-gated surface together — reminders, escalation, overdue tiles,
     * KPI, AI context, overdue filters. ONE list: every gate uses this, never
     * a hand-typed pair (BUG-005/006/007 rule 1/3).
     */
    public static function openStatuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_ON_PROGRESS];
    }

    /** Record-level twin of openStatuses() — display ternaries, action visibility. */
    public function isOpenWork(): bool
    {
        return in_array($this->status, self::openStatuses(), true);
    }

    /**
     * The ONE due-day definition (reminder command + notifications): signed
     * whole days until due_date — positive = remaining, negative = overdue,
     * null = TBD. `round()` (not truncating int-cast): a row due exactly 7
     * days out reports 7, and "due yesterday" reports 1 day overdue — the
     * truncating cast reported 6 and 0.
     */
    public function daysUntilDue(): ?int
    {
        return $this->due_date === null
            ? null
            : (int) round(now()->diffInDays($this->due_date, false));
    }

    /** Priority — optional everywhere, default low (owner rule 2026-10-14). */
    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'incident_id',
        'title',
        'detail',
        'due_date',
        'pic_email',
        'reminder',
        'reminder_frequency',
        'priority',
        'status',
        'last_reminded_at',
        'reminders_sent',
        'completed_at',
        'completed_by',
        'result_note',
        'cancellation_reason',
        'escalation_level',
        'last_escalated_at',
    ];

    protected $casts = [
        'pic_email' => 'array',
        // MySQL DATE columns don't need this, but SQLite tests store raw strings —
        // cast keeps ->format() working on both drivers (notification mail depends on it).
        'due_date' => 'date',
        'last_reminded_at' => 'datetime',
        'reminders_sent' => 'integer',
        'completed_at' => 'datetime',
        'escalation_level' => 'integer',
        'last_escalated_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** Who flipped this to done — stamped by ActionImprovementObserver. */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** Encrypted proof files attached to this action (uploadable anytime). */
    public function evidence(): HasMany
    {
        return $this->hasMany(ActionImprovementEvidence::class);
    }

    /**
     * Worker-submitted proof awaiting a trusted approval (owner 2026-11-20).
     * The ONE pending-proof definition — the Approve action's visibility,
     * its server-side guard and its bulk-stamp query all read this shape
     * (submitted_by set, approved_at null). Admin-tab uploads never count.
     */
    public function hasPendingProof(): bool
    {
        // Same ONE pending-proof definition either way (submitted_by set,
        // approved_at null). When the evidence relation is eager-loaded
        // (Action Improvements tables) this is a collection check, not a
        // per-row EXISTS query — the tables evaluate it inside visible().
        if ($this->relationLoaded('evidence')) {
            return $this->evidence->contains(
                fn (ActionImprovementEvidence $e) => filled($e->submitted_by) && $e->approved_at === null
            );
        }

        return $this->evidence()
            ->whereNotNull('submitted_by')
            ->whereNull('approved_at')
            ->exists();
    }

    /**
     * Record that a reminder email actually went out (mail-eligible
     * recipients > 0). The per-user in-app halves already persist as
     * `notifications` rows — this tracks the combined email itself, and
     * last_reminded_at anchors the reminder_frequency throttle.
     */
    public function recordReminderSent(int $mailedCount): void
    {
        if ($mailedCount <= 0) {
            return;
        }

        $this->forceFill([
            'last_reminded_at' => now(),
            'reminders_sent' => ($this->reminders_sent ?? 0) + 1,
        ])->saveQuietly();
    }
}
