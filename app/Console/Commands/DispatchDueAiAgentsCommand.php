<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AiAgentFrequency;
use App\Enums\AiAgentRunStatus;
use App\Models\AiAgent;
use App\Models\AiAgentRun;
use Cron\CronExpression;
use Illuminate\Console\Command;

class DispatchDueAiAgentsCommand extends Command
{
    protected $signature = 'ai:dispatch-due-agents';

    protected $description = 'Dispatch queued runs for cron/one-shot scheduled AI agents (and fail stale runs from dead workers)';

    public function handle(): int
    {
        $this->failStaleRuns();

        // Explicit now() — isDue() without an argument uses raw time() and
        // ignores Carbon test-time. Evaluated in the app timezone.
        $due = AiAgent::query()
            ->where('enabled', true)
            ->where('frequency', AiAgentFrequency::Cron->value)
            ->whereNotNull('cron_expression')
            // Same-minute dedupe: never dispatch an agent twice within one minute.
            ->where(fn ($q) => $q->whereNull('last_run_at')->orWhere('last_run_at', '<', now()->startOfMinute()))
            ->get()
            ->filter(fn (AiAgent $agent) => CronExpression::factory($agent->cron_expression)->isDue(now()));

        foreach ($due as $agent) {
            $agent->dispatchRun();
            $this->info("Dispatched agent: {$agent->name}");
        }

        // One-shot agents: due once run_at has passed and this occurrence hasn't
        // been dispatched yet (last_run_at < run_at — dispatchRun stamps
        // last_run_at, a failed run re-arms run_at into the future). The JOB,
        // not this command, parks the agent on success or re-arms the bounded
        // retry on failure — the command never disables, so failures stay retryable.
        $dueOnce = AiAgent::query()
            ->where('enabled', true)
            ->where('frequency', AiAgentFrequency::Once->value)
            ->whereNotNull('run_at')
            ->where('run_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('last_run_at')->orWhereColumn('last_run_at', '<', 'run_at'))
            ->get();

        foreach ($dueOnce as $agent) {
            $agent->dispatchRun();
            $this->info("Dispatched one-shot agent: {$agent->name}");
        }

        return self::SUCCESS;
    }

    /**
     * ponytail: 15-min staleness threshold > job timeout 300s — any run still
     * "running" past that had its worker die. Precise per-run heartbeats if this
     * ever mislabels long runs.
     */
    private function failStaleRuns(): void
    {
        AiAgentRun::query()
            ->where('status', AiAgentRunStatus::Running->value)
            ->where('started_at', '<', now()->subMinutes(15))
            ->each(function (AiAgentRun $run) {
                $run->forceFill([
                    'status' => AiAgentRunStatus::Failed->value,
                    'error_message' => 'Marked failed — worker died mid-run (stale sweep).',
                    'completed_at' => now(),
                ])->save();
            });
    }
}
