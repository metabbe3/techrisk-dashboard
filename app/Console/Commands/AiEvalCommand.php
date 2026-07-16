<?php

namespace App\Console\Commands;

use App\Services\Ai\Evaluation\AiEvalService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Evaluate incident retrieval quality against a golden set.
 *
 * Golden set = JSON array of {"query": "...", "expected_ids": [id, ...]}.
 * Default location: storage/ai_eval_golden_set.json (override with --set).
 * Prints precision@k per case + the mean — the number every retrieval change
 * (hybrid, reranker, embeddings) should move.
 */
class AiEvalCommand extends Command
{
    protected $signature = 'ai:eval
                            {--set= : path to golden set JSON (array of {query, expected_ids})}
                            {--k=5 : precision@k}';

    protected $description = 'Evaluate incident retrieval (precision@k) against a golden set';

    public function handle(AiEvalService $eval): int
    {
        $setPath = $this->option('set') ?: base_path('storage/ai_eval_golden_set.json');

        if (! file_exists($setPath)) {
            $this->warn("No golden set found at {$setPath}.");
            $this->info('Create one: JSON array of {"query": "...", "expected_ids": [incident_id, ...]}');
            $this->info('Then re-run, or pass --set=/path/to/set.json');

            return 1;
        }

        $cases = json_decode((string) file_get_contents($setPath), true);
        if (! is_array($cases)) {
            $this->error('Invalid golden set JSON (expected an array of {query, expected_ids}).');

            return 1;
        }

        $k = (int) $this->option('k');
        $report = $eval->evaluateSet($cases, $k);

        $rows = array_map(fn ($r) => [
            Str::limit($r['query'], 50),
            round($r['precision'], 2),
            implode(',', $r['retrieved']) ?: '-',
            implode(',', $r['expected']) ?: '-',
        ], $report['results']);

        $this->table(['query', "p@{$k}", 'retrieved', 'expected'], $rows);
        $this->newLine();
        $this->info(sprintf('MEAN precision@%d = %.3f over %d case(s)', $k, $report['mean_precision'], count($report['results'])));

        return 0;
    }
}
