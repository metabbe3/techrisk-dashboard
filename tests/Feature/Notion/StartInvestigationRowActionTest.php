<?php

namespace Tests\Feature\Notion;

use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Filament\Resources\IssueResource\Pages\ListIssues;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notion\NotionInvestigationService;
use App\Services\Notion\NotionMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PROJ-018: "Start Investigation" lives in the existing "…" row kebab of
 * both tables (Incidents and Issues). It creates the Notion investigation
 * page via the shared NotionInvestigationService and hides itself once a
 * page is linked (the view page's section takes over from there).
 */
class StartInvestigationRowActionTest extends TestCase
{
    use RefreshDatabase;

    protected const PARENT_ID = 'aa11bb22cc33dd44ee55ff6600778899';

    protected const CREATED_ID = '1234abcd5678ef901234abcd5678ef90';

    /** Owner 2026-11-04 (round 9): Start Investigation is incident-only — the Issues table never defines it. */
    public function test_the_issues_kebab_has_no_start_investigation_action(): void
    {
        $this->grant('manage issues', 'view issues');

        // Dropdown contents lazy-render client-side, so the definition — not
        // the rendered HTML — is the honest assertion.
        $names = collect(Livewire::actingAs($this->user)->test(ListIssues::class)->instance()->getTable()->getActions())
            ->flatMap(fn ($action) => $action instanceof \Filament\Actions\ActionGroup ? $action->getActions() : [$action])
            ->map(fn ($action) => method_exists($action, 'getName') ? $action->getName() : null)
            ->filter()
            ->values();

        $this->assertNotContains('start_investigation', $names);
    }

    public function test_the_action_is_visible_in_the_incidents_kebab_until_a_page_exists(): void
    {
        $this->grant('manage incidents', 'view incidents');
        $incident = $this->issue(['classification' => 'Incident']);

        Livewire::actingAs($this->user)->test(ListIncidents::class)
            ->assertTableActionVisible('start_investigation', $incident);
    }

    public function test_the_action_is_hidden_in_the_incidents_kebab_once_a_page_exists(): void
    {
        $this->grant('manage incidents', 'view incidents');
        $incident = $this->issue(['classification' => 'Incident', 'notion_investigation_page_id' => self::CREATED_ID]);

        Livewire::actingAs($this->user)->test(ListIncidents::class)
            ->assertTableActionHidden('start_investigation', $incident);
    }

    public function test_the_action_is_hidden_without_the_manage_incidents_permission(): void
    {
        $this->grant('view incidents');

        Livewire::actingAs($this->user)->test(ListIncidents::class)
            ->assertTableActionHidden('start_investigation', $this->issue(['classification' => 'Incident']));
    }

    /**
     * Owner 2026-11-04 (round 10): a soft-unlinked record keeps its page id
     * — Start Investigation shows again and CONTINUES the existing page
     * instead of creating a duplicate.
     */
    public function test_the_action_is_visible_on_a_soft_unlinked_record_and_continues_the_page(): void
    {
        $this->grant('manage incidents', 'view incidents');
        $incident = $this->issue([
            'classification' => 'Incident',
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_unlinked_at' => '2026-11-04 00:00:00',
            // Continuing a page must not clobber a deliberate status — only a
            // FRESH start moves the record to In progress.
            'incident_status' => 'Finalization',
        ]);
        // The continue path only fetches the page itself — any create-pages
        // call would fail `once()`.
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn(['structured' => null, 'isError' => false, 'text' => json_encode([
                'metadata' => ['type' => 'page'],
                'text' => '<page url="https://app.notion.com/p/'.self::CREATED_ID.'">'
                    .'<content>continued body</content></page>',
            ])]);

        Livewire::actingAs($this->user)->test(ListIncidents::class)
            ->assertTableActionVisible('start_investigation', $incident)
            ->callTableAction('start_investigation', $incident)
            ->assertNotified();

        $fresh = $incident->fresh();
        $this->assertSame(self::CREATED_ID, $fresh->notion_investigation_page_id);
        $this->assertSame('continued body', $fresh->notion_investigation_markdown);
        $this->assertNull($fresh->notion_investigation_unlinked_at);
        $this->assertSame('Finalization', $fresh->incident_status->value);
    }

    public function test_starting_from_the_incidents_table_creates_the_page_and_notifies(): void
    {
        $this->grant('manage incidents', 'view incidents');
        Setting::set(NotionInvestigationService::PARENT_KEY, self::PARENT_ID);
        $incident = $this->issue(['classification' => 'Incident']);
        $this->mockConnectedCreate($incident->title);

        Livewire::actingAs($this->user)->test(ListIncidents::class)
            ->callTableAction('start_investigation', $incident)
            ->assertNotified();

        $fresh = $incident->fresh();
        $this->assertSame(self::CREATED_ID, $fresh->notion_investigation_page_id);
        $this->assertSame('In progress', $fresh->incident_status->value);
    }

    public function test_a_failed_start_shows_the_failure_message_on_incidents(): void
    {
        $this->grant('manage incidents', 'view incidents');
        Setting::set(NotionInvestigationService::PARENT_KEY, self::PARENT_ID);
        $incident = $this->issue(['classification' => 'Incident']);
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            // The service fetches the parent first to detect its type.
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::PARENT_ID])
            ->andReturn($this->pageParentResponse())
            ->shouldReceive('callToolDetailed')->once()->with('notion-create-pages', Mockery::any())
            ->andReturn([
                'structured' => null, 'text' => 'boom', 'isError' => true,
            ]);

        Livewire::actingAs($this->user)->test(ListIncidents::class)
            ->callTableAction('start_investigation', $incident)
            ->assertNotified('Investigation not started');

        $this->assertNull($incident->fresh()->notion_investigation_page_id);
    }

    /** The table itself carries a small Notion link for records with an investigation page. */
    public function test_the_table_shows_a_notion_link_for_linked_records_only(): void
    {
        $this->grant('manage issues', 'view issues');
        $linked = $this->issue(['notion_investigation_page_id' => self::CREATED_ID]);
        $unlinked = $this->issue();

        $issuesHtml = Livewire::actingAs($this->user)->test(ListIssues::class)->html();

        $this->assertStringContainsString('https://www.notion.so/'.self::CREATED_ID, $issuesHtml);
        $this->assertStringNotContainsString('https://www.notion.so/', str_replace('https://www.notion.so/'.self::CREATED_ID, '', $issuesHtml));

        $this->grant('manage incidents', 'view incidents');
        $incident = $this->issue(['classification' => 'Incident', 'notion_investigation_page_id' => self::CREATED_ID]);
        $unlinkedIncident = $this->issue(['classification' => 'Incident']);

        $incidentsHtml = Livewire::actingAs($this->user)->test(ListIncidents::class)->html();

        $this->assertStringContainsString('https://www.notion.so/'.self::CREATED_ID, $incidentsHtml);
        $this->assertStringNotContainsString('https://www.notion.so/', str_replace('https://www.notion.so/'.self::CREATED_ID, '', $incidentsHtml));
    }

    private function grant(string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo($permissions);
    }

    private function issue(array $attributes = []): Incident
    {
        // The factory randomizes classification; the Issues table only lists
        // classification = Issue, so pin it (incidents pass it explicitly).
        return Incident::factory()->create(array_merge([
            'classification' => \App\Enums\IncidentClassification::Issue->value,
        ], $attributes));
    }

    private function mockConnectedCreate(string $mustContainTitle): void
    {
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            // The service fetches the parent first to detect its type.
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::PARENT_ID])
            ->andReturn($this->pageParentResponse())
            ->shouldReceive('callToolDetailed')->once()->with('notion-create-pages', Mockery::on(
                fn (array $args) => str_contains(
                    (string) ($args['pages'][0]['properties']['title'] ?? ''),
                    $mustContainTitle
                )
            ))
            ->andReturn(['structured' => ['id' => self::CREATED_ID], 'text' => 'Created page.', 'isError' => false]);
    }

    /** A notion-fetch response for a plain page parent (no data-source-state). */
    private function pageParentResponse(): array
    {
        return ['structured' => null, 'isError' => false, 'text' => json_encode([
            'metadata' => ['type' => 'page'],
            'text' => '<page url="https://app.notion.com/p/'.self::PARENT_ID.'">'
                .'<content>Team pages live here.</content></page>',
        ])];
    }
}
