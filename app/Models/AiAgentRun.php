<?php

namespace App\Models;

use App\Enums\AiAgentRunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class AiAgentRun extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;
    use Prunable;

    protected $fillable = [
        'agent_id',
        'triggered_by_run_id',
        'status',
        'input',
        'output',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'response_time_ms',
        'error_message',
        'requested_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => AiAgentRunStatus::class,
        'requested_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'agent_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'triggered_by_run_id');
    }

    public function prunable(): Builder
    {
        // Only terminal runs; a pending/running row is never pruned away mid-flight.
        return static::whereNotNull('completed_at')
            ->where('completed_at', '<', now()->subDays((int) config('ai.agents.run_retention_days', 90)));
    }
}
