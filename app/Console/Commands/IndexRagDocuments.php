<?php

namespace App\Console\Commands;

use App\Services\Ai\RagService;
use Illuminate\Console\Command;

class IndexRagDocuments extends Command
{
    protected $signature = 'rag:index
                            {--force : Delete all existing documents and re-index from scratch}
                            {--chunk=100 : Number of incidents to process per batch}';

    protected $description = 'Index incidents into RAG documents for full-text similarity search';

    public function handle(RagService $ragService): int
    {
        $force = $this->option('force');
        $chunk = (int) $this->option('chunk');

        if ($force) {
            $deleted = $ragService->reindexStale() > 0 ? 're-indexed stale' : 'no stale';
            $this->info('Running full re-index...');

            $count = $ragService->indexAllIncidents();

            $this->info("Indexed {$count} incident(s).");

            return self::SUCCESS;
        }

        $count = $ragService->reindexStale();
        $this->info("Re-indexed {$count} stale document(s).");

        if ($count === 0) {
            $this->comment('All documents are up to date. Use --force to re-index everything.');
        }

        return self::SUCCESS;
    }
}
