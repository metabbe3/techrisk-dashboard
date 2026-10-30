<?php

namespace Tests\Feature\Notion;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Models\Setting;
use App\Services\Notion\NotionIssueSyncService;
use App\Services\Notion\NotionMcpClient;
use App\Services\Notion\NotionSyncedPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class NotionIssueSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected const COLLECTION_URL = 'collection://2c72556e-44dd-81cf-8977-000bc43d6f86';

    public function test_fails_without_a_watch_target(): void
    {
        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('watch target', $result['message']);
    }

    public function test_fails_when_notion_is_not_connected(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');

        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->once()->andReturn(false);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('not connected', $result['message']);
    }

    public function test_imports_new_database_rows_as_issues(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('4062556e44dd81c395b0c542f2599858', 'Summary of Incident - DB down', '2026-01-12', 'P1', 'Infrastructure (Database)'),
        ]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['created']);

        $incident = Incident::query()->sole();

        $this->assertSame('DB down', $incident->title);
        $this->assertSame('2026-01-12', $incident->incident_date->toDateString());
        $this->assertSame('P1', $incident->severity->value);
        $this->assertSame(IncidentClassification::Issue->value, $incident->classification->value);
        $this->assertNotNull($incident->no);
        $this->assertSame('2026-01-12', $incident->entry_date_tech_risk->toDateString());

        $this->assertTrue(NotionSyncedPage::where('page_id', '4062556e44dd81c395b0c542f2599858')->where('incident_id', $incident->id)->exists());
        $this->assertNotNull(Setting::get(NotionIssueSyncService::LAST_SYNC_AT_KEY));
        $this->assertSame('Imported 1 issue(s).', $result['message']);
    }

    public function test_deleting_an_imported_issue_restores_it_on_the_next_sync(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $row = $this->row('4062556e44dd81c395b0c542f2599858', 'DB down', '2026-01-12', 'P2', 'Code Bug', createdTime: '2026-07-01 00:00:00Z');

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->twice()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            // First run — no watermark yet: full backfill scan.
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => ! str_contains($args['data']['query'] ?? '', 'WHERE')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => [$row]]), 'isError' => false])
            // Second run — recovery fetches the deleted page directly.
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', "WHERE url LIKE '%4062556e44dd81c395b0c542f2599858%'")
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => [$row]]), 'isError' => false])
            // The incremental window itself is untouched by the recovery.
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', "WHERE createdTime >= '2026-07-01 00:00:00Z'")
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => [$row]]), 'isError' => false]);

        app(NotionIssueSyncService::class)->sync();

        $incident = Incident::query()->sole();

        $incident->delete();

        // The FK sets NULL instead of cascading: the page row survives as the
        // tombstone that tells the next sync this page needs re-importing.
        $this->assertSame(1, NotionSyncedPage::count());
        $this->assertNull(NotionSyncedPage::query()->sole()->incident_id);

        // Restore is opt-in (owner 2026-10-29) — only a full sync brings
        // deleted issues back.
        $result = app(NotionIssueSyncService::class)->sync(restoreDeleted: true);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['created']);
        $this->assertSame('Imported 1 issue(s).', $result['message']);
        $this->assertSame('DB down', Incident::query()->sole()->title);
        $this->assertSame(1, NotionSyncedPage::count());
        $this->assertNull(NotionSyncedPage::query()->whereNull('incident_id')->first());
        // Recovery fetches by page ID — the watermark never moves.
        $this->assertSame('2026-07-01 00:00:00Z', Setting::get(NotionIssueSyncService::WATERMARK_KEY));
    }

    public function test_a_page_deleted_in_notion_drops_the_tombstone(): void
    {
        NotionSyncedPage::create(['page_id' => 'aaaa556e44dd81c395b0c542f2599aaa', 'incident_id' => null]);
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');

        // Permanent removal (owner 2026-10-28): the page no longer exists in
        // Notion, so the tombstone is garbage-collected and nothing imports.
        // Only a full sync looks at tombstones at all (owner 2026-10-29).
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', "WHERE url LIKE '%aaaa556e44dd81c395b0c542f2599aaa%'")
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => []]), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', 'WHERE createdTime >=')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => []]), 'isError' => false]);

        $result = app(NotionIssueSyncService::class)->sync(restoreDeleted: true);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('No new issues.', $result['message']);
        $this->assertSame(0, NotionSyncedPage::count());
        $this->assertSame(0, Incident::count());
    }

    public function test_a_failed_deleted_page_lookup_keeps_the_tombstone(): void
    {
        NotionSyncedPage::create(['page_id' => 'aaaa556e44dd81c395b0c542f2599aaa', 'incident_id' => null]);
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', 'WHERE url LIKE')
            ))
            ->andReturn(['structured' => null, 'text' => 'Notion returned an error', 'isError' => true])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', 'WHERE createdTime >=')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => []]), 'isError' => false]);

        $result = app(NotionIssueSyncService::class)->sync(restoreDeleted: true);

        // The tombstone stays so the recovery retries on the next run.
        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, NotionSyncedPage::query()->whereNull('incident_id')->count());
        $this->assertSame(0, Incident::count());
    }

    public function test_a_deleted_page_whose_title_already_exists_is_skipped(): void
    {
        Incident::create([
            'title' => 'DB down',
            'no' => '20260101_IS_1111',
            'incident_date' => '2026-01-10',
            'entry_date_tech_risk' => '2026-01-10',
            'severity' => 'P2',
            'classification' => IncidentClassification::Issue->value,
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
        ]);
        NotionSyncedPage::create(['page_id' => 'aaaa556e44dd81c395b0c542f2599aaa', 'incident_id' => null]);
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', 'WHERE url LIKE')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => [
                $this->row('aaaa556e44dd81c395b0c542f2599aaa', 'Summary of Incident - DB down', '2026-01-12', 'P2', 'Code Bug'),
            ]]), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', 'WHERE createdTime >=')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => []]), 'isError' => false]);

        $result = app(NotionIssueSyncService::class)->sync(restoreDeleted: true);

        // Same-title issue already exists — restoring would duplicate it.
        $this->assertSame('ok', $result['status']);
        $this->assertSame('No new issues; 1 duplicate title(s).', $result['message']);
        $this->assertSame(0, NotionSyncedPage::count());
        $this->assertSame(1, Incident::count());
    }

    public function test_dry_run_leaves_dangling_rows_and_the_watermark_untouched(): void
    {
        NotionSyncedPage::create(['page_id' => 'aaaa556e44dd81c395b0c542f2599aaa', 'incident_id' => null]);
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');

        // Dry-run without the restore flag never looks at tombstones (restore
        // is opt-in, owner 2026-10-29), so only the incremental window is
        // queried. The dangling row and watermark stay put.
        $this->mockConnectedNotion([
            $this->row('bbbb556e44dd81c395b0c542f2599bbb', 'Would import', '2026-07-01', 'P3', 'Data Issue', createdTime: '2026-07-01 00:00:00Z'),
        ], expectWatermark: '2026-06-01 00:00:00Z');

        app(NotionIssueSyncService::class)->sync(dryRun: true);

        $this->assertSame(1, NotionSyncedPage::query()->whereNull('incident_id')->count());
        $this->assertSame('2026-06-01 00:00:00Z', Setting::get(NotionIssueSyncService::WATERMARK_KEY));
        $this->assertSame(0, Incident::count());
    }

    public function test_a_normal_sync_does_not_restore_deleted_issues(): void
    {
        NotionSyncedPage::create(['page_id' => 'aaaa556e44dd81c395b0c542f2599aaa', 'incident_id' => null]);
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');

        // Deleting from the dashboard is permanent (owner 2026-10-29): the
        // normal sync never looks at tombstones — no page lookup happens.
        $this->mockConnectedNotion([], expectWatermark: '2026-06-01 00:00:00Z');

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame('No new issues.', $result['message']);
        $this->assertSame(1, NotionSyncedPage::query()->whereNull('incident_id')->count());
        $this->assertSame(0, Incident::count());
    }

    public function test_dry_run_with_restore_previews_restores_without_touching_anything(): void
    {
        NotionSyncedPage::create(['page_id' => 'aaaa556e44dd81c395b0c542f2599aaa', 'incident_id' => null]);
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');

        // The preview shows what a full sync would bring back — read-only:
        // the tombstone survives and nothing is written.
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', "WHERE url LIKE '%aaaa556e44dd81c395b0c542f2599aaa%'")
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => [
                $this->row('aaaa556e44dd81c395b0c542f2599aaa', 'Deleted by accident', '2026-05-20', 'P2', 'Code Bug'),
            ]]), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', 'WHERE createdTime >=')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => []]), 'isError' => false]);

        $result = app(NotionIssueSyncService::class)->sync(dryRun: true, restoreDeleted: true);

        $this->assertSame('ok', $result['status']);
        $this->assertStringContainsString('Would restore', (string) $result['preview']);
        $this->assertStringContainsString('Deleted by accident', (string) $result['preview']);
        $this->assertSame(1, NotionSyncedPage::query()->whereNull('incident_id')->count());
        $this->assertSame(0, Incident::count());
    }

    public function test_a_notion_row_whose_title_matches_a_manual_incident_is_skipped(): void
    {
        // The pre-sync backlog was partly entered as Incidents (owner
        // 2026-10-29) — title dedupe must see them or the first sync doubles
        // every one of them.
        Incident::create([
            'title' => 'DB down',
            'no' => '20260101_IN_1111',
            'incident_date' => '2026-01-10',
            'entry_date_tech_risk' => '2026-01-10',
            'severity' => 'P2',
            'classification' => IncidentClassification::Incident->value,
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
        ]);

        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('4062556e44dd81c395b0c542f2599858', 'Summary of Incident - DB down', '2026-01-12', 'P1', 'Infrastructure (Database)'),
        ]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, Incident::count());
        $this->assertSame(IncidentClassification::Incident->value, Incident::query()->sole()->classification->value);
        $this->assertSame(0, NotionSyncedPage::count());
    }

    public function test_dry_run_itemizes_each_finding_on_its_own_line(): void
    {
        $existing = Incident::create([
            'title' => 'Already there',
            'no' => '20260101_IS_1111',
            'incident_date' => '2026-01-10',
            'entry_date_tech_risk' => '2026-01-10',
            'severity' => 'P2',
            'classification' => IncidentClassification::Issue->value,
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
        ]);
        NotionSyncedPage::create(['page_id' => 'cccc556e44dd81c395b0c542f2599ccc', 'incident_id' => $existing->id]);

        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('aaaa556e44dd81c395b0c542f2599aaa', 'Brand new row', '2026-07-01', 'P3', 'Data Issue', createdTime: '2026-07-01 00:00:00Z'),
            $this->row('bbbb556e44dd81c395b0c542f2599bbb', 'Already there', '2026-07-02', 'P3', 'Data Issue', createdTime: '2026-07-02 00:00:00Z'),
            $this->row('cccc556e44dd81c395b0c542f2599ccc', 'Synced before', '2026-07-03', 'P3', 'Data Issue', createdTime: '2026-07-03 00:00:00Z'),
        ]);

        $result = app(NotionIssueSyncService::class)->sync(dryRun: true);

        $preview = (string) $result['preview'];
        // Findings get their own line each; the EXPECTED already-synced state
        // is a count in the message, never a line — a first-run preview used
        // to be a wall of "Already synced from Notion" rows hiding the real
        // findings (owner 2026-10-30).
        $this->assertStringContainsString('- Brand new row', $preview);
        $this->assertStringContainsString('- Duplicate title (already in the dashboard): Already there', $preview);
        $this->assertStringNotContainsString('Synced before', $preview);
        $this->assertSame('Would import 1 issue(s); 1 already synced; 1 duplicate title(s).', $result['message']);
    }

    public function test_the_boundary_reread_is_never_reported_as_a_skip(): void
    {
        $existing = Incident::create([
            'title' => 'Already there',
            'no' => '20260101_IS_1111',
            'incident_date' => '2026-01-10',
            'entry_date_tech_risk' => '2026-01-10',
            'severity' => 'P2',
            'classification' => IncidentClassification::Issue->value,
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
        ]);
        NotionSyncedPage::create(['page_id' => 'aaaa556e44dd81c395b0c542f2599aaa', 'incident_id' => $existing->id]);
        NotionSyncedPage::create(['page_id' => 'bbbb556e44dd81c395b0c542f2599bbb', 'incident_id' => $existing->id]);
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-07-01 00:00:00Z');

        $this->mockConnectedNotion([
            // The `>=` window re-reads this row every run — an expected
            // re-read, never a finding.
            $this->row('aaaa556e44dd81c395b0c542f2599aaa', 'Synced before', '2026-07-01', 'P3', 'Data Issue', createdTime: '2026-07-01 00:00:00Z'),
            $this->row('bbbb556e44dd81c395b0c542f2599bbb', 'Later synced', '2026-07-02', 'P3', 'Data Issue', createdTime: '2026-07-02 00:00:00Z'),
        ], expectWatermark: '2026-07-01 00:00:00Z');

        $result = app(NotionIssueSyncService::class)->sync(dryRun: true);

        // Neither row is a finding, so neither gets a line; only 'Later
        // synced' counts — the boundary re-read is invisible.
        $this->assertSame('', (string) $result['preview']);
        $this->assertSame('No new issues; 1 already synced.', $result['message']);
    }

    public function test_classification_column_wins_over_root_cause_mapping(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            // Root cause "Deployment Issues" maps to P1 — the explicit P3 wins.
            $this->row('4062556e44dd81c395b0c542f2599858', 'DB down', '2026-01-12', 'P3', 'Deployment Issues'),
        ]);

        app(NotionIssueSyncService::class)->sync();

        $this->assertSame('P3', Incident::query()->sole()->severity->value);
    }

    public function test_second_run_does_not_duplicate_and_queries_since_the_watermark(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $row = $this->row('4062556e44dd81c395b0c542f2599858', 'DB down', '2026-01-12', 'P2', 'Code Bug');

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            // First run — no watermark yet: full backfill scan.
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => ($args['data']['data_source_urls'][0] ?? null) === self::COLLECTION_URL
                    && ! str_contains($args['data']['query'] ?? '', 'WHERE')
                    && str_contains($args['data']['query'] ?? '', 'ORDER BY createdTime ASC')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => [$row]]), 'isError' => false])
            // Second run — reads only rows created at/after the first run's newest row.
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', "WHERE createdTime >= '2026-01-12 00:00:00Z'")
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => [$row]]), 'isError' => false]);

        app(NotionIssueSyncService::class)->sync();
        $result = app(NotionIssueSyncService::class)->sync();

        // The re-fetched row shares the watermark's second — an expected
        // boundary re-read, not a finding, so the run reports nothing.
        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame('No new issues.', $result['message']);
        $this->assertSame(1, Incident::count());
        $this->assertSame(1, NotionSyncedPage::count());
    }

    public function test_same_second_straggler_at_the_boundary_still_imports(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-07-01 00:00:00Z');
        $this->mockConnectedNotion([
            // createdTime equals the watermark but the page is new — a
            // same-second straggler the previous run's query missed. The
            // boundary flag must never suppress the import itself.
            $this->row('cccc556e44dd81c395b0c542f2599ccc', 'Boundary straggler', '2026-07-01', 'P3', 'Data Issue', createdTime: '2026-07-01 00:00:00Z'),
        ], expectWatermark: '2026-07-01 00:00:00Z');

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['created']);
        $this->assertSame('Boundary straggler', Incident::sole()->title);
    }

    public function test_incremental_run_reads_only_rows_since_the_watermark(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');
        $this->mockConnectedNotion([
            $this->row('aaaa556e44dd81c395b0c542f2599aaa', 'Only brand new row', '2026-07-01', 'P3', 'Data Issue', createdTime: '2026-07-01 00:00:00Z'),
        ], expectWatermark: '2026-06-01 00:00:00Z');

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['created']);
        $this->assertSame('2026-07-01 00:00:00Z', Setting::get(NotionIssueSyncService::WATERMARK_KEY));
    }

    public function test_watermark_advances_to_the_newest_row_seen_including_skipped_ones(): void
    {
        Incident::create([
            'title' => 'Older issue',
            'no' => '20260101_IS_1111',
            'incident_date' => '2026-01-10',
            'entry_date_tech_risk' => '2026-01-10',
            'severity' => 'P2',
            'classification' => IncidentClassification::Issue->value,
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
        ]);

        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('aaaa556e44dd81c395b0c542f2599aaa', 'Fresh row', '2026-07-01', 'P3', 'Data Issue', createdTime: '2026-07-01 00:00:00Z'),
            // Skipped (title matches an existing issue) but still SEEN — the
            // watermark must move past it so it is never re-read.
            $this->row('bbbb556e44dd81c395b0c542f2599bbb', 'Older issue', '2026-08-01', 'P2', 'Code Bug', createdTime: '2026-08-01 00:00:00Z'),
        ]);

        app(NotionIssueSyncService::class)->sync();

        $this->assertSame('2026-08-01 00:00:00Z', Setting::get(NotionIssueSyncService::WATERMARK_KEY));
        $this->assertSame(2, Incident::count());
    }

    public function test_dry_run_does_not_advance_the_watermark(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('4062556e44dd81c395b0c542f2599858', 'DB down', '2026-01-12', 'P2', 'Code Bug'),
        ]);

        app(NotionIssueSyncService::class)->sync(dryRun: true);

        $this->assertNull(Setting::get(NotionIssueSyncService::WATERMARK_KEY));
    }

    public function test_failed_sync_keeps_the_watermark(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->andReturn(null);

        app(NotionIssueSyncService::class)->sync();

        $this->assertSame('2026-06-01 00:00:00Z', Setting::get(NotionIssueSyncService::WATERMARK_KEY));
    }

    public function test_rows_already_imported_by_title_are_skipped(): void
    {
        Incident::create([
            'title' => 'DB down',
            'no' => '20260101_IS_1111',
            'incident_date' => '2026-01-10',
            'entry_date_tech_risk' => '2026-01-10',
            'severity' => 'P2',
            'classification' => IncidentClassification::Issue->value,
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
        ]);

        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('4062556e44dd81c395b0c542f2599858', 'Summary of Incident - DB down', '2026-01-12', 'P1', 'Infrastructure (Database)'),
        ]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('No new issues; 1 duplicate title(s).', $result['message']);
        $this->assertSame(1, Incident::count());
    }

    public function test_a_dry_run_paginates_past_the_per_query_row_cap(): void
    {
        // Field evidence (prod, 2026-10-30): the Notion engine returned
        // exactly 100 rows of a larger database — the preview's counts were
        // silently capped ("99 already synced" for a bigger table). A dry
        // run must walk the whole window so its numbers are real.
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');

        $firstPage = [];
        foreach (range(1, 100) as $i) {
            $firstPage[] = $this->row(
                sprintf('%032x', $i),
                "Row {$i}",
                '2026-01-12',
                'P3',
                'Data Issue',
                createdTime: sprintf('2026-07-01 00:00:%02dZ', $i - 1),
            );
        }

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => ! str_contains($args['data']['query'] ?? '', 'WHERE')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => $firstPage]), 'isError' => false])
            // Continuation: `>=` the last page's tail so same-second ties are
            // never skipped; the tail row itself comes back as a re-read and
            // the URL dedupe drops it.
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', "WHERE createdTime >= '2026-07-01 00:00:99Z'")
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => [
                $firstPage[99],
                $this->row(str_repeat('a', 32), 'Row 101', '2026-01-12', 'P3', 'Data Issue', createdTime: '2026-07-02 00:00:00Z'),
                $this->row(str_repeat('b', 32), 'Row 102', '2026-01-12', 'P3', 'Data Issue', createdTime: '2026-07-02 00:00:01Z'),
            ]]), 'isError' => false]);

        $result = app(NotionIssueSyncService::class)->sync(dryRun: true);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(102, $result['created']);
        $this->assertSame('Would import 102 issue(s).', $result['message']);
    }

    public function test_dry_run_creates_nothing(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('4062556e44dd81c395b0c542f2599858', 'DB down', '2026-01-12', 'P2', 'Code Bug'),
        ]);

        $result = app(NotionIssueSyncService::class)->sync(dryRun: true);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['created']);
        $this->assertStringContainsString('DB down', (string) $result['preview']);
        $this->assertSame(0, Incident::count());
        $this->assertSame(0, NotionSyncedPage::count());
        $this->assertNull(Setting::get(NotionIssueSyncService::LAST_SYNC_AT_KEY));
    }

    public function test_fetch_failure_leaves_database_untouched(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->andReturn(null);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertSame(0, Incident::count());
        $this->assertStringContainsString('failed', (string) Setting::get(NotionIssueSyncService::LAST_SYNC_STATUS_KEY));
    }

    public function test_fetch_mcp_error_is_surfaced(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->andReturn([
                'structured' => null,
                'text' => 'Could not find page: db-1',
                'isError' => true,
            ]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Notion fetch failed: Could not find page: db-1', $result['message']);
        $this->assertSame(0, Incident::count());
    }

    public function test_watch_target_that_is_not_a_database_reports_the_raw_shape(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->andReturn([
                'structured' => null,
                'text' => '# A plain page with no collection reference',
                'isError' => false,
            ]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('not a Notion database', $result['message']);
        $this->assertSame('# A plain page with no collection reference', $result['preview']);
    }

    public function test_query_mcp_error_is_surfaced(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(fn ($args) => is_array($args['data'] ?? null)))
            ->andReturn(['structured' => null, 'text' => 'no such column: "Bogus"', 'isError' => true]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('query failed: no such column', $result['message']);
    }

    public function test_unparseable_query_response_reports_raw_shape_for_inspection(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(fn ($args) => is_array($args['data'] ?? null)))
            ->andReturn(['structured' => null, 'text' => 'Here is what I found, in prose.', 'isError' => false]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Here is what I found, in prose.', $result['preview']);
    }

    public function test_unmapped_severity_defaults_to_g(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('4062556e44dd81c395b0c542f2599858', 'New kind of problem', '2026-02-02', '-', 'Never Seen Before'),
        ]);

        app(NotionIssueSyncService::class)->sync();

        $this->assertSame('G', Incident::query()->sole()->severity->value);
    }

    public function test_a_row_that_fails_to_import_does_not_block_later_rows(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');

        // Simulates the realistic poison pill: a write that throws under
        // MySQL strict mode (e.g. a Notion title longer than the 255-char
        // incidents.title column).
        Incident::creating(fn (Incident $incident) => $incident->title === 'Poison row'
            ? throw new \RuntimeException('SQLSTATE[22001]: data too long')
            : null);

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', 'WHERE createdTime >=')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => [
                $this->row('aaaa556e44dd81c395b0c542f2599aaa', 'Good row', '2026-07-01', 'P3', 'Data Issue', createdTime: '2026-07-01 00:00:00Z'),
                $this->row('bbbb556e44dd81c395b0c542f2599bbb', 'Poison row', '2026-07-02', 'P3', 'Data Issue', createdTime: '2026-07-02 00:00:00Z'),
            ]]), 'isError' => false]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('partial', $result['status']);
        $this->assertSame(1, $result['created']);
        $this->assertStringContainsString('1 failed', $result['message']);
        // The failure is diagnosable: named in the preview with its reason.
        $this->assertStringContainsString('Poison row', (string) $result['preview']);
        $this->assertStringContainsString('data too long', (string) $result['preview']);
        // The row after the poison still imported; the poison left no orphan.
        $this->assertSame('Good row', Incident::query()->sole()->title);
        $this->assertSame(0, NotionSyncedPage::where('page_id', 'bbbb556e44dd81c395b0c542f2599bbb')->count());
        // The watermark holds at the first failure — the row is retried next run.
        $this->assertSame('2026-07-01 00:00:00Z', Setting::get(NotionIssueSyncService::WATERMARK_KEY));
        $this->assertStringContainsString('partial', (string) Setting::get(NotionIssueSyncService::LAST_SYNC_STATUS_KEY));
    }

    public function test_a_fixed_poison_row_imports_on_the_next_run(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');

        // The row fails exactly once (admin fixes the Notion title in between).
        $thrown = false;
        Incident::creating(function (Incident $incident) use (&$thrown) {
            if ($incident->title === 'Poison row' && ! $thrown) {
                $thrown = true;

                throw new \RuntimeException('SQLSTATE[22001]: data too long');
            }
        });

        $rows = [
            $this->row('aaaa556e44dd81c395b0c542f2599aaa', 'Good row', '2026-07-01', 'P3', 'Data Issue', createdTime: '2026-07-01 00:00:00Z'),
            $this->row('bbbb556e44dd81c395b0c542f2599bbb', 'Poison row', '2026-07-02', 'P3', 'Data Issue', createdTime: '2026-07-02 00:00:00Z'),
        ];

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->twice()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->twice()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', 'WHERE createdTime >=')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => $rows]), 'isError' => false]);

        $first = app(NotionIssueSyncService::class)->sync();
        $this->assertSame('partial', $first['status']);
        $this->assertSame(1, $first['created']);

        // Run 2 re-reads the window: the good row is the boundary re-read,
        // the fixed poison row finally imports, and the watermark moves on.
        $second = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('ok', $second['status']);
        $this->assertSame(1, $second['created']);
        $this->assertSame(2, Incident::count());
        $this->assertSame('2026-07-02 00:00:00Z', Setting::get(NotionIssueSyncService::WATERMARK_KEY));
    }

    public function test_a_failed_reimport_keeps_the_tombstone_for_the_next_run(): void
    {
        NotionSyncedPage::create(['page_id' => 'aaaa556e44dd81c395b0c542f2599aaa', 'incident_id' => null]);
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');

        Incident::creating(fn (Incident $incident) => $incident->title === 'Poison'
            ? throw new \RuntimeException('SQLSTATE[22001]: data too long')
            : null);

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            // Recovery lookup finds the page…
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', 'WHERE url LIKE')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => [
                $this->row('aaaa556e44dd81c395b0c542f2599aaa', 'Poison', '2026-01-12', 'P2', 'Code Bug'),
            ]]), 'isError' => false])
            // …the incremental window is empty.
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', 'WHERE createdTime >=')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => []]), 'isError' => false]);

        $result = app(NotionIssueSyncService::class)->sync(restoreDeleted: true);

        $this->assertSame('partial', $result['status']);
        // The tombstone survived the rolled-back import — the restore retries.
        $this->assertSame(1, NotionSyncedPage::query()->whereNull('incident_id')->count());
        $this->assertSame(0, Incident::count());
    }

    public function test_a_full_sync_restores_only_the_picked_pages(): void
    {
        NotionSyncedPage::create(['page_id' => 'aaaa556e44dd81c395b0c542f2599aaa', 'incident_id' => null]);
        NotionSyncedPage::create(['page_id' => 'bbbb556e44dd81c395b0c542f2599bbb', 'incident_id' => null]);
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            // Only the PICKED page is looked up — the unpicked one never
            // reaches Notion (an unexpected lookup would fail the mock).
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', "WHERE url LIKE '%aaaa556e44dd81c395b0c542f2599aaa%'")
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => [
                $this->row('aaaa556e44dd81c395b0c542f2599aaa', 'DB down', '2026-01-12', 'P2', 'Code Bug'),
            ]]), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', 'WHERE createdTime >=')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => []]), 'isError' => false]);

        $result = app(NotionIssueSyncService::class)->sync(
            restoreDeleted: true,
            restorePageIds: ['aaaa556e44dd81c395b0c542f2599aaa'],
        );

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['created']);
        $this->assertSame('DB down', Incident::query()->sole()->title);
        // The unpicked tombstone survived — its issue stays deleted.
        $this->assertTrue(NotionSyncedPage::query()
            ->where('page_id', 'bbbb556e44dd81c395b0c542f2599bbb')
            ->whereNull('incident_id')
            ->exists());
    }

    public function test_a_full_sync_with_an_empty_selection_restores_nothing(): void
    {
        NotionSyncedPage::create(['page_id' => 'aaaa556e44dd81c395b0c542f2599aaa', 'incident_id' => null]);
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            // An empty pick queries Notion for NO deleted page — the picker
            // filtered the tombstones before the lookups.
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', 'WHERE createdTime >=')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => []]), 'isError' => false]);

        $result = app(NotionIssueSyncService::class)->sync(restoreDeleted: true, restorePageIds: []);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, Incident::count());
        // The tombstone survives — the issue stays deleted.
        $this->assertSame(1, NotionSyncedPage::query()->whereNull('incident_id')->count());
    }

    public function test_restorable_issues_lists_exactly_what_a_full_sync_would_restore(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        NotionSyncedPage::create(['page_id' => 'aaaa556e44dd81c395b0c542f2599aaa', 'incident_id' => null]);
        NotionSyncedPage::create(['page_id' => 'bbbb556e44dd81c395b0c542f2599bbb', 'incident_id' => null]);
        NotionSyncedPage::create(['page_id' => 'cccc556e44dd81c395b0c542f2599ccc', 'incident_id' => null]);
        Incident::factory()->create(['title' => 'Duplicate of something already here']);

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->andReturnUsing(function (string $tool, array $args): array {
                $query = (string) ($args['data']['query'] ?? '');

                $respond = fn (array $rows): array => ['structured' => null, 'text' => json_encode(['results' => $rows]), 'isError' => false];

                if (str_contains($query, '%aaaa556e44dd81c395b0c542f2599aaa%')) {
                    return $respond([$this->row('aaaa556e44dd81c395b0c542f2599aaa', 'DB down', '2026-01-12', 'P2', 'Code Bug')]);
                }

                // Gone from Notion — nothing to restore.
                if (str_contains($query, '%bbbb556e44dd81c395b0c542f2599bbb%')) {
                    return $respond([]);
                }

                // A title the dashboard already has — a restore would be skipped.
                if (str_contains($query, '%cccc556e44dd81c395b0c542f2599ccc%')) {
                    return $respond([$this->row('cccc556e44dd81c395b0c542f2599ccc', 'Duplicate of something already here', '2026-02-02', 'P3', 'Data Issue')]);
                }

                return $respond([]);
            });

        $options = app(NotionIssueSyncService::class)->restorableIssues();

        $this->assertSame(['aaaa556e44dd81c395b0c542f2599aaa' => 'DB down (2026-01-12, P2)'], $options);
    }

    public function test_restorable_issues_returns_nothing_when_notion_is_not_connected(): void
    {
        NotionSyncedPage::create(['page_id' => 'aaaa556e44dd81c395b0c542f2599aaa', 'incident_id' => null]);
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');

        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->once()->andReturn(false);

        $this->assertSame([], app(NotionIssueSyncService::class)->restorableIssues());
    }

    public function test_title_dedup_loads_the_issue_table_once_per_run(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('aaaa556e44dd81c395b0c542f2599aaa', 'First row', '2026-01-12', 'P3', 'Data Issue'),
            $this->row('bbbb556e44dd81c395b0c542f2599bbb', 'Second row', '2026-01-13', 'P3', 'Data Issue'),
        ]);

        DB::enableQueryLog();
        app(NotionIssueSyncService::class)->sync();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        // One title pluck for the whole run — never a per-row full hydration
        // of every Issue (O(rows × table) on a growing backlog).
        $plucks = array_filter($queries, fn (string $q) => str_contains($q, 'select "title" from "incidents"'));
        $hydrations = array_filter($queries, fn (string $q) => str_contains($q, 'select * from "incidents" where "classification"'));

        $this->assertCount(1, $plucks);
        $this->assertCount(0, $hydrations);
        $this->assertSame(2, Incident::count());
    }

    /**
     * Chain the two-call flow: notion-fetch returns the data-source document
     * carrying the collection URL, then notion-query-data-sources returns rows.
     * $expectWatermark asserts the query's incremental WHERE clause: null = no
     * WHERE (first run / full backfill), a string = rows since that createdTime.
     */
    private function mockConnectedNotion(array $rows, int $times = 1, ?string $expectWatermark = null): void
    {
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->times($times)->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->times($times)->with('notion-query-data-sources', Mockery::on(
                function (array $args) use ($expectWatermark) {
                    $query = $args['data']['query'] ?? '';

                    if (($args['data']['data_source_urls'][0] ?? null) !== self::COLLECTION_URL
                        || ! str_contains($query, self::COLLECTION_URL)
                        || ! str_contains($query, 'ORDER BY createdTime ASC')) {
                        return false;
                    }

                    return $expectWatermark === null
                        ? ! str_contains($query, 'WHERE')
                        : str_contains($query, "WHERE createdTime >= '{$expectWatermark}'");
                }
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => $rows]), 'isError' => false]);
    }

    /** Minimal excerpt of the real fetched data-source document. */
    private function fetchText(): string
    {
        return '<data-source url="{{'.self::COLLECTION_URL.'}}">'
            .'"name":"Summary of Incident 2026 (DB)","url":"'.self::COLLECTION_URL.'"'
            .'</data-source>';
    }

    /** One SQL-result row from notion-query-data-sources (live shape, 2026-10-27). */
    private function row(string $pageId, string $title, string $dateStart, string $classification, string $rootCause, string $createdTime = '2026-01-12 00:00:00Z'): array
    {
        return [
            'url' => 'https://app.notion.com/'.$pageId,
            'createdTime' => $createdTime,
            'Name' => $title,
            'date_start' => $dateStart,
            'classification' => $classification,
            'root_cause' => $rootCause,
        ];
    }
}
