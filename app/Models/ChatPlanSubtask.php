<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatPlanSubtask extends Model
{
    use HasUuids;

    protected $fillable = [
        'plan_id',
        'conversation_id',
        'subtask_index',
        'description',
        'persona_key',
        'dynamic_prompt',
        'status',
        'result',
        'model',
        'tokens_used',
        'response_time_ms',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'subtask_index' => 'integer',
        'tokens_used' => 'integer',
        'response_time_ms' => 'integer',
        'metadata' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function scopeByPlanId($query, string $planId)
    {
        return $query->where('plan_id', $planId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running']);
    }

    public function markCompleted(string $result, string $model, int $tokensUsed, int $responseTimeMs, array $metadata = []): void
    {
        $update = [
            'status' => 'completed',
            'result' => $result,
            'model' => $model,
            'tokens_used' => $tokensUsed,
            'response_time_ms' => $responseTimeMs,
        ];

        if (! empty($metadata)) {
            $update['metadata'] = array_merge($this->metadata ?? [], $metadata);
        }

        $this->update($update);
    }

    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }
}
