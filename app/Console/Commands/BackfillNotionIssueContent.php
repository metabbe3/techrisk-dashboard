<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notion\NotionIssueSyncService;
use App\Services\Notion\NotionMcpClient;
use App\Services\Notion\NotionSyncedPage;
use Illuminate\Console\Command;

/**
 * One-shot backfill: fetch the Notion page body for already-synced Issues
 * that were imported before the sync filled content fields. Run manually
 * after deploying the page-body feature: php artisan notion:backfill-issue-content
 */
class BackfillNotionIssueContent extends Command
{
    protected $signature = 'notion:backfill-issue-content {--limit=} {--force}';

    protected $description = 'Fetch Notion page bodies for synced Issues missing content (summary/root cause)';

    public function handle(NotionMcpClient $notion, NotionIssueSyncService $sync): int
    {
        if (! $notion->connected()) {
            $this->error('Notion is not connected — connect it on the Notion Settings page.');

            return self::FAILURE;
        }

        $pages = NotionSyncedPage::query()
            ->whereNotNull('incident_id')
            // Only rows still missing content — re-runs skip already-filled ones.
            // --force re-fetches every synced row (overwrite junk-filled fields).
            ->when(! $this->option('force'), fn ($query) => $query
                ->whereHas('incident', fn ($query) => $query->whereNull('summary')->whereNull('root_cause')))
            ->orderBy('id')
            ->when(filled($this->option('limit')), fn ($query) => $query->limit((int) $this->option('limit')))
            ->get();

        if ($pages->isEmpty()) {
            $this->info('No synced issues are missing content.');

            return self::SUCCESS;
        }

        $filled = 0;

        foreach ($pages as $page) {
            $incident = $page->incident;

            if ($incident === null) {
                continue;
            }

            if ($sync->fetchAndAttachBody($incident, $page->page_id)) {
                $filled++;
                $this->info("Filled: [{$incident->no}] {$incident->title}");
            } else {
                $this->warn("Skipped (fetch failed or empty body): {$page->page_id}");
            }

            // ponytail: fixed 300ms sleep ≈ 3 req/s Notion limit; batch queue if volume ever grows
            usleep(300000);
        }

        $this->info("Done — {$filled}/".$pages->count().' issue(s) filled.');

        return self::SUCCESS;
    }
}
