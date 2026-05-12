<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarRoomSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'incident_id',
        'title',
        'status',
        'current_round',
        'max_rounds',
        'model',
        'moderator_model',
        'enable_web_search',
        'selected_agents',
        'incident_context',
        'context_summarized',
        'user_instructions',
        'final_report',
        'final_report_html',
        'started_at',
        'completed_at',
        'failed_at',
        'error_message',
        'tokens_used',
    ];

    protected $casts = [
        'selected_agents' => 'array',
        'incident_context' => 'array',
        'final_report' => 'array',
        'enable_web_search' => 'boolean',
        'context_summarized' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'current_round' => 'integer',
        'max_rounds' => 'integer',
        'tokens_used' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class, 'war_room_session_incidents', 'session_id', 'incident_id');
    }

    public function isMultiIncident(): bool
    {
        return $this->incidents()->count() > 1;
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WarRoomMessage::class, 'session_id')
            ->orderBy('round')
            ->orderBy('created_at');
    }

    public function roundMessages(int $round): HasMany
    {
        return $this->hasMany(WarRoomMessage::class, 'session_id')
            ->where('round', $round)
            ->orderBy('created_at');
    }

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function getAgentRoles(): array
    {
        return $this->selected_agents ?? [];
    }

    public function getNextRound(): int
    {
        return $this->current_round + 1;
    }

    public function advanceRound(): void
    {
        $this->increment('current_round');
    }

    public function markRunning(): void
    {
        if ($this->status !== 'pending') {
            return;
        }
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function markCompleted(): void
    {
        if ($this->status !== 'running') {
            return;
        }
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        if ($this->status === 'completed') {
            return;
        }
        $this->update([
            'status' => 'failed',
            'failed_at' => now(),
            'error_message' => $error,
        ]);
    }

    public function addTokens(int $tokens): void
    {
        $this->increment('tokens_used', $tokens);
    }

    public function scopeForUser($query, ?int $userId = null)
    {
        return $query->where('user_id', $userId ?? auth()->id());
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('updated_at', 'desc');
    }

    public function scopeForIncident($query, string $incidentId)
    {
        return $query->whereHas('incidents', fn ($q) => $q->where('incidents.id', $incidentId))
            ->orWhere('incident_id', $incidentId);
    }
}
