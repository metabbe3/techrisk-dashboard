<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Markdown\IncidentMarkdownCorpusService;
use Illuminate\Console\Command;

/**
 * Rebuilds the persistent incident memory (markdown/corpus) when stale.
 * Runs hourly from the scheduler; --force rebuilds regardless — the
 * Agent Memory page button and this share the service.
 */
class RefreshIncidentCorpusCommand extends Command
{
    protected $signature = 'incidents:refresh-corpus {--force : Rebuild even when the manifest says fresh}';

    protected $description = 'Rebuild the persistent incident memory corpus (markdown/corpus) when stale';

    public function handle(IncidentMarkdownCorpusService $corpus): int
    {
        if (! $this->option('force') && ! $corpus->isStale()) {
            $this->info('Incident memory is fresh — skipped.');

            return self::SUCCESS;
        }

        $manifest = $corpus->refresh();
        $this->info("Incident memory rebuilt: {$manifest['incidents']} incidents.");

        return self::SUCCESS;
    }
}
