<?php

namespace App\Console\Commands;

use App\Services\Ai\RagService;
use Illuminate\Console\Command;

class IndexAllRagDocumentsCommand extends Command
{
    protected $signature = 'rag:index-all';

    protected $description = 'Index all incidents into the RAG documents table';

    public function handle(RagService $ragService): int
    {
        $this->info('Indexing all incidents...');

        $count = $ragService->indexAllIncidents();

        $this->info("Done. Indexed {$count} incidents.");

        return self::SUCCESS;
    }
}
