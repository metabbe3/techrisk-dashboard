<?php

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

    protected $description = 'Dispatch queued runs for cron-scheduled AI agents (and fail stale runs from dead workers)';

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
