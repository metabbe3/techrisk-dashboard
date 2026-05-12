<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class WarRoomMessage extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'round',
        'agent_role',
        'role',
        'content',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'response_time_ms',
        'status',
        'web_search_context',
        'error_message',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'round' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'response_time_ms' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(WarRoomSession::class, 'session_id');
    }

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running']);
    }

    public function markCompleted(string $content, array $usage = [], int $responseTimeMs = 0): void
    {
        $this->update([
            'status' => 'completed',
            'content' => $content,
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'response_time_ms' => $responseTimeMs,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);
    }
}
