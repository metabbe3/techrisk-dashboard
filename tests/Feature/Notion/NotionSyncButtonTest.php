<?php

namespace Tests\Feature\Notion;

use App\Filament\Resources\IssueResource\Pages\ListIssues;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notion\NotionIssueSyncService;
use App\Services\Notion\NotionMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Issue-list header button ("Sync with Notion", left of Import) runs the
 * same sync + notification flow as the Notion Settings page — via the shared
 * NotionSyncAction runner — and is gated on `manage api tokens`, not
 * `manage issues` (the connection is a workspace-wide admin resource).
 */
class NotionSyncButtonTest extends TestCase
{
    use RefreshDatabase;

    protected const COLLECTION_URL = 'collection://2c72556e-44dd-81cf-8977-000bc43d6f86';

    public function test_user_with_api_token_permission_sees_and_runs_the_button(): void
    {
        $this->grant('manage api tokens', 'view issues');
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('4062556e44dd81c395b0c542f2599858', 'Synced from the list page', '2026-07-01', 'P3', 'Data Issue'),
        ]);

        Livewire::actingAs($this->user)->test(ListIssues::class)
            ->assertActionVisible('syncWithNotion')
            ->callAction('syncWithNotion')
            ->assertNotified();

        $this->assertSame(1, Incident::count());
        $this->assertSame('Synced from the list page', Incident::sole()->title);
    }

    public function test_user_without_api_token_permission_does_not_see_the_button(): void
    {
        // `manage issues` (Import/New Issue) deliberately does NOT unlock the
        // Notion connection.
        $this->grant('manage issues', 'view issues');

        Livewire::actingAs($this->user)->test(ListIssues::class)
            ->assertActionHidden('syncWithNotion');
    }

    public function test_failed_sync_notifies_with_the_preview(): void
    {
        $this->grant('manage api tokens', 'view issues');
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->andReturn([
                'structured' => null,
                'text' => 'no such column: "Bogus"',
                'isError' => true,
            ]);

        Livewire::actingAs($this->user)->test(ListIssues::class)
            ->callAction('syncWithNotion')
            ->assertNotified('Sync failed');

        $this->assertSame(0, Incident::count());
    }

    public function test_the_list_button_always_runs_a_normal_sync(): void
    {
        // Full sync lives on the Notion Settings page only (owner
        // 2026-10-29) — the list button has no checkbox and ignores any
        // posted restoreDeleted data.
        $this->grant('manage api tokens', 'view issues');
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(false, false, null)
            ->andReturn(['status' => 'ok', 'message' => 'No new issues.', 'created' => 0, 'skipped' => 0, 'preview' => null]);

        Livewire::actingAs($this->user)->test(ListIssues::class)
            ->callAction('syncWithNotion', data: ['restoreDeleted' => true])
            ->assertNotified('No new issues.');
    }

    private function grant(string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo($permissions);
    }

    private function mockConnectedNotion(array $rows): void
    {
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => ($args['data']['data_source_urls'][0] ?? null) === self::COLLECTION_URL
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => $rows]), 'isError' => false]);
    }

    private function fetchText(): string
    {
        return '<data-source url="{{'.self::COLLECTION_URL.'}}">'
            .'"name":"Summary of Incident 2026 (DB)","url":"'.self::COLLECTION_URL.'"'
            .'</data-source>';
    }

    private function row(string $pageId, string $title, string $dateStart, string $classification, string $rootCause): array
    {
        return [
            'url' => 'https://app.notion.com/'.$pageId,
            'createdTime' => '2026-07-01 00:00:00Z',
            'Name' => $title,
            'date_start' => $dateStart,
            'classification' => $classification,
            'root_cause' => $rootCause,
        ];
    }
}
