<?php

namespace App\Console\Commands;

use App\Services\Ai\RagService;
use Illuminate\Console\Command;

class ReindexRagDocumentsCommand extends Command
{
    protected $signature = 'rag:reindex-stale';

    protected $description = 'Re-index RAG documents that are stale (incident updated after last indexing)';

    public function handle(RagService $ragService): int
    {
        $count = $ragService->reindexStale();
        $this->info("Re-indexed {$count} stale documents.");

        return self::SUCCESS;
    }
}
