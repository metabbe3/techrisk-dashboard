<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiAgentFrequency;
use App\Enums\AiAgentRunStatus;
use App\Jobs\Ai\RunAiAgentJob;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

class AiAgent extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'description',
        'instructions',
        'expected_output',
        'require_json',
        'model',
        'include_context',
        'include_memory',
        'include_documents',
        'include_corpus',
        'depends_on_agent_id',
        'reports_to_agent_id',
        'frequency',
        'cron_expression',
        'notify_email',
        'enabled',
        'last_run_at',
    ];

    protected $casts = [
        'frequency' => AiAgentFrequency::class,
        'include_context' => 'boolean',
        'include_memory' => 'boolean',
        'include_documents' => 'boolean',
        'include_corpus' => 'boolean',
        'require_json' => 'boolean',
        'enabled' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(AiAgentRun::class, 'agent_id');
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_agent_id');
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_agent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(AiAgentFile::class, 'agent_id');
    }

    /**
     * Would pointing $column at $targetId (from agent $ignoreId) create a cycle?
     * Walks the chain upward through the same column; a visited-set guards
     * against pre-existing corrupt loops in the DB.
     */
    public static function createsDependencyCycle(string $column, ?string $targetId, ?string $ignoreId): bool
    {
        if (blank($targetId) || blank($ignoreId)) {
            return false; // create-time: no row exists yet, nothing can point back
        }

        $seen = [];
        $current = $targetId;

        while ($current !== null) {
            if ($current === $ignoreId || isset($seen[$current])) {
                return true;
            }

            $seen[$current] = true;
            $current = static::query()->whereKey($current)->value($column);
        }

        return false;
    }

    public function lastRun(): HasOne
    {
        return $this->hasOne(AiAgentRun::class, 'agent_id')->latestOfMany('requested_at');
    }

    /**
     * Friendly schedule <-> cron translation. The form shows presets (daily at
     * a time, hourly, weekly); the dispatcher keeps speaking raw cron — these
     * two helpers are the only bridge, so no other surface ever changes.
     *
     * @param  array{type:string, time:string, weekday:int}  $preset
     */
    public static function cronFromPreset(string $type, ?string $time = null, ?int $weekday = null): ?string
    {
        $time ??= '09:00';
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return match ($type) {
            'hourly' => (int) $minute.' * * * *',
            'daily' => (int) $minute.' '.(int) $hour.' * * *',
            'weekly' => (int) $minute.' '.(int) $hour.' * * '.($weekday ?? 1),
            default => null, // manual (no cron) or custom (caller supplies the raw expression)
        };
    }

    /**
     * @return array{type:string, time:string, weekday:int}
     */
    public function schedulePresetFromCron(): array
    {
        if ($this->frequency !== AiAgentFrequency::Cron || blank($this->cron_expression)) {
            return ['type' => 'manual', 'time' => '09:00', 'weekday' => 1];
        }

        $cron = trim($this->cron_expression);

        if (preg_match('/^(\d+) \* \* \* \*$/', $cron, $m)) {
            return ['type' => 'hourly', 'time' => '00:'.sprintf('%02d', (int) $m[1]), 'weekday' => 1];
        }

        if (preg_match('/^(\d+) (\d+) \* \* \*$/', $cron, $m)) {
            return ['type' => 'daily', 'time' => sprintf('%02d', (int) $m[2]).':'.sprintf('%02d', (int) $m[1]), 'weekday' => 1];
        }

        if (preg_match('/^(\d+) (\d+) \* \* ([1-7])$/', $cron, $m)) {
            return ['type' => 'weekly', 'time' => sprintf('%02d', (int) $m[2]).':'.sprintf('%02d', (int) $m[1]), 'weekday' => (int) $m[3]];
        }

        return ['type' => 'custom', 'time' => '09:00', 'weekday' => 1];
    }

    /**
     * Computed, never stored — a stored value goes stale the moment the cron
     * expression is edited.
     */
    public function getNextRunAtAttribute(): ?Carbon
    {
        if (! $this->enabled || $this->frequency !== AiAgentFrequency::Cron || blank($this->cron_expression)) {
            return null;
        }

        try {
            return Carbon::instance(
                CronExpression::factory($this->cron_expression)->getNextRunDate(now())
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Single dispatch path for manual ("Run Now"), scheduled, and chained runs:
     * create the Pending run row (with an instructions snapshot), queue the job,
     * and stamp last_run_at at dispatch time so the scheduler's same-minute
     * guard works. Chained dispatches pass their triggering run for provenance.
     */
    public function dispatchRun(?string $triggeredByRunId = null): AiAgentRun
    {
        $run = AiAgentRun::create([
            'agent_id' => $this->id,
            'triggered_by_run_id' => $triggeredByRunId,
            'status' => AiAgentRunStatus::Pending->value,
            'input' => $this->instructions,
            'requested_at' => now(),
        ]);

        RunAiAgentJob::dispatch($this->id, $run->id);

        $this->forceFill(['last_run_at' => now()])->save();

        return $run;
    }
}
