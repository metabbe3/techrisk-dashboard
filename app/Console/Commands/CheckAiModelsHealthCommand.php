<?php

namespace App\Console\Commands;

use App\Services\Ai\AiTextService;
use Illuminate\Console\Command;

class CheckAiModelsHealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:check-model-health';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ping every configured AI model through the gateway and cache reachability + latency';

    /**
     * Execute the console command.
     */
    public function handle(AiTextService $ai): int
    {
        if (! config('ai.model_health.enabled', true)) {
            $this->info('Model health check is disabled (AI_MODEL_HEALTH_CHECK=false).');

            return self::SUCCESS;
        }

        $models = $ai->getAvailableModels();

        if (empty($models)) {
            $this->warn('No AI models configured.');

            return self::SUCCESS;
        }

        $this->info('Pinging '.count($models).' model(s) through the gateway...');
        $results = $ai->checkModelsHealth();

        $rows = collect($results)
            ->map(fn ($r, $id) => [
                $id,
                $r['status'] ?? 'unknown',
                isset($r['latency_ms']) ? number_format($r['latency_ms']).' ms' : '—',
                $r['error'] ?? '—',
            ])
            ->values()
            ->toArray();

        $this->table(['Model', 'Status', 'Latency', 'Error'], $rows);

        $summary = collect($results)->groupBy(fn ($r) => $r['status'] ?? 'unknown')->map->count();
        $this->newLine();
        $this->info('Done: '.$summary->map(fn ($count, $status) => "{$count} {$status}")->implode(', '));

        return self::SUCCESS;
    }
}
