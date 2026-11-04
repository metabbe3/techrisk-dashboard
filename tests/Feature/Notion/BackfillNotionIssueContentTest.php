<?php

namespace Tests\Feature\Notion;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Services\Notion\NotionMcpClient;
use App\Services\Notion\NotionSyncedPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillNotionIssueContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_fills_content_for_synced_issues_missing_it_and_skips_filled_ones(): void
    {
        $bare = $this->syncedIssue(['summary' => null, 'root_cause' => null]);
        $filled = $this->syncedIssue(['summary' => 'Already has content', 'root_cause' => 'known']);

        $body = "# Page\n\nIntro text.\n\n## Root Cause\n\nDead database.";
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => $bare->pageId])
            ->andReturn(['structured' => null, 'text' => $body, 'isError' => false]);

        $this->artisan('notion:backfill-issue-content')
            ->expectsOutputToContain("Filled: [{$bare->incident->no}] {$bare->incident->title}")
            ->assertSuccessful();

        $this->assertSame('Dead database.', $bare->incident->fresh()->root_cause);
        // The already-filled issue is not in the candidate set — no fetch for it.
        $this->assertSame('known', $filled->incident->fresh()->root_cause);
    }

    public function test_reports_when_nothing_needs_backfilling(): void
    {
        $this->syncedIssue(['summary' => 'Already has content', 'root_cause' => 'known']);

        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true);

        $this->artisan('notion:backfill-issue-content')
            ->expectsOutputToContain('No synced issues are missing content.')
            ->assertSuccessful();
    }

    public function test_fails_fast_when_notion_is_not_connected(): void
    {
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(false);

        $this->artisan('notion:backfill-issue-content')->assertFailed();
    }

    public function test_force_refetches_rows_whose_content_is_already_filled(): void
    {
        // A junk-filled row (e.g. first backfill caught the fetch envelope)
        // is invisible to the missing-content filter — only --force reaches it.
        $junk = $this->syncedIssue(['summary' => 'Here is the result of "fetch"…', 'root_cause' => null]);

        $body = 'Root Cause'."\n\n".'Dead database.';
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => $junk->pageId])
            ->andReturn(['structured' => null, 'text' => $body, 'isError' => false]);

        $this->artisan('notion:backfill-issue-content', ['--force' => true])
            ->expectsOutputToContain("Filled: [{$junk->incident->no}] {$junk->incident->title}")
            ->assertSuccessful();

        $this->assertSame('Dead database.', $junk->incident->fresh()->root_cause);
    }

    /** @return object{incident: Incident, pageId: string} */
    private function syncedIssue(array $incidentOverrides): object
    {
        $incident = Incident::factory()->create(array_merge([
            'classification' => IncidentClassification::Issue,
        ], $incidentOverrides));
        $pageId = 'aaaa556e44dd81c395b0c542f2599'.substr(md5($incident->id), 0, 4);

        NotionSyncedPage::create(['page_id' => $pageId, 'incident_id' => $incident->id]);

        return (object) ['incident' => $incident, 'pageId' => $pageId];
    }
}
