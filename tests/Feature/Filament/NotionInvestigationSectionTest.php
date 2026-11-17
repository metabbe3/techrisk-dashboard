<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\IncidentClassification;
use App\Filament\Resources\IncidentResource\Pages\ViewIncident;
use App\Filament\Resources\IssueResource\Pages\ViewIssue;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notion\NotionInvestigationService;
use App\Services\Notion\NotionMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PROJ-018: the linked Notion investigation page surfaces on both view pages
 * — a shared "Notion Investigation" section (link + synced markdown) and a
 * "Refresh from Notion" header action, both only once a page is linked. The
 * Issue view's first infolist() override must keep rendering the base issue
 * fields.
 */
class NotionInvestigationSectionTest extends TestCase
{
    use RefreshDatabase;

    protected const CREATED_ID = '1234abcd5678ef901234abcd5678ef90';

    /** The fresh page a re-investigation creates (never the deleted one). */
    protected const NEW_ID = 'fedcba0987654321fedcba0987654321';

    protected const PARENT_ID = 'aa11bb22cc33dd44ee55ff6600778899';

    public function test_the_section_renders_on_the_incident_view_when_a_page_exists(): void
    {
        $incident = $this->incident(['notion_investigation_page_id' => self::CREATED_ID, 'notion_investigation_markdown' => '## Findings\n\nPool exhaustion confirmed.']);

        $html = Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->html();

        $this->assertStringContainsString('Notion Investigation Pages', $html);
        $this->assertStringContainsString('Pool exhaustion confirmed.', $html);
        $this->assertStringContainsString('https://www.notion.so/'.self::CREATED_ID, $html);
    }

    public function test_the_section_is_hidden_on_the_incident_view_without_a_page(): void
    {
        $incident = $this->incident();

        $html = Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->html();

        $this->assertStringNotContainsString('Notion Investigation Pages', $html);
    }

    public function test_the_section_renders_on_the_issue_view_and_keeps_the_base_fields(): void
    {
        $issue = $this->incident([
            'classification' => IncidentClassification::Issue,
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => '## Findings\n\nBad config.',
        ]);

        $html = Livewire::actingAs($this->manager('issues'))
            ->test(ViewIssue::class, ['record' => (string) $issue->id])
            ->html();

        // The infolist override must not lose the issue fields the page
        // rendered before (as a disabled form).
        $this->assertStringContainsString('Issue Name', $html);
        $this->assertStringContainsString('Start Date', $html);
        $this->assertStringContainsString('Issue ID', $html);
        $this->assertStringContainsString($issue->title, $html);

        $this->assertStringContainsString('Notion Investigation Pages', $html);
        $this->assertStringContainsString('Bad config.', $html);
        $this->assertStringContainsString('https://www.notion.so/'.self::CREATED_ID, $html);
    }

    public function test_the_section_is_hidden_on_the_issue_view_without_a_page(): void
    {
        $issue = $this->incident(['classification' => IncidentClassification::Issue]);

        $html = Livewire::actingAs($this->manager('issues'))
            ->test(ViewIssue::class, ['record' => (string) $issue->id])
            ->html();

        $this->assertStringNotContainsString('Notion Investigation Pages', $html);
    }

    public function test_refresh_footer_action_stores_the_fetched_markdown(): void
    {
        $incident = $this->incident([
            'classification' => IncidentClassification::Issue,
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'old body',
            // Fresh copy — the auto-refresh-on-open (round 8) must stay out of
            // this test; the two fetches below are the two manual footer calls.
            'notion_investigation_synced_at' => now(),
        ]);
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            // notion-fetch returns a JSON envelope; the stored markdown is the
            // <content> body inside it.
            ->shouldReceive('callToolDetailed')->twice()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn(['structured' => null, 'isError' => false, 'text' => json_encode([
                'metadata' => ['type' => 'page'],
                'text' => '<page url="https://app.notion.com/p/'.self::CREATED_ID.'">'
                    .'<content>fresh body from Notion</content></page>',
            ])]);

        Livewire::actingAs($this->manager('issues'))
            ->test(ViewIssue::class, ['record' => (string) $incident->id])
            ->callInfolistAction('notion_investigation', 'refresh_investigation')
            ->assertNotified('Investigation refreshed from Notion.');
        $this->assertSame('fresh body from Notion', $incident->fresh()->notion_investigation_markdown);

        Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->callInfolistAction('notion_investigation', 'refresh_investigation')
            ->assertNotified('Investigation refreshed from Notion.');
        $this->assertSame('fresh body from Notion', $incident->fresh()->notion_investigation_markdown);
    }

    /** Owner 2026-11-04 (round 8): the Notion pair sits NEXT to "Open in Notion" in the section footer, not in a header kebab. */
    public function test_the_notion_actions_render_in_the_section_footer_and_not_the_header(): void
    {
        $incident = $this->incident(['notion_investigation_page_id' => self::CREATED_ID]);

        $html = Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->html();

        $this->assertStringContainsString('Open in Notion', $html);
        $this->assertStringContainsString('Refresh from Notion', $html);
        $this->assertStringContainsString('Unlink investigation', $html);

        $issue = $this->incident([
            'classification' => IncidentClassification::Issue,
            'notion_investigation_page_id' => self::CREATED_ID,
        ]);

        $issueHtml = Livewire::actingAs($this->manager('issues'))
            ->test(ViewIssue::class, ['record' => (string) $issue->id])
            ->html();

        $this->assertStringContainsString('Open in Notion', $issueHtml);
        $this->assertStringContainsString('Refresh from Notion', $issueHtml);
        $this->assertStringContainsString('Unlink investigation', $issueHtml);
    }

    public function test_the_notion_actions_are_absent_when_no_page_exists(): void
    {
        $issue = $this->incident(['classification' => IncidentClassification::Issue]);

        $issueHtml = Livewire::actingAs($this->manager('issues'))
            ->test(ViewIssue::class, ['record' => (string) $issue->id])
            ->html();

        $this->assertStringNotContainsString('Refresh from Notion', $issueHtml);
        $this->assertStringNotContainsString('Unlink investigation', $issueHtml);

        $incident = $this->incident();

        $html = Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->html();

        $this->assertStringNotContainsString('Refresh from Notion', $html);
        $this->assertStringNotContainsString('Unlink investigation', $html);
    }

    /**
     * Owner 2026-11-04 (round 10): unlinking a LIVE page is soft — the page
     * id stays so the section survives, and Relink / Start Investigation
     * pick the same page back up. Only the stored copy clears.
     */
    public function test_unlink_footer_action_soft_unlinks_and_keeps_the_page(): void
    {
        $issue = $this->incident([
            'classification' => IncidentClassification::Issue,
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'stale body',
            'notion_investigation_synced_at' => '2026-01-01 00:00:00',
        ]);

        Livewire::actingAs($this->manager('issues'))
            ->test(ViewIssue::class, ['record' => (string) $issue->id])
            ->callInfolistAction('notion_investigation', 'unlink_investigation')
            ->assertNotified('Investigation link removed');

        $fresh = $issue->fresh();
        $this->assertSame(self::CREATED_ID, $fresh->notion_investigation_page_id);
        $this->assertNull($fresh->notion_investigation_markdown);
        $this->assertNull($fresh->notion_investigation_synced_at);
        $this->assertNotNull($fresh->notion_investigation_unlinked_at);

        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'stale body',
        ]);

        Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->callInfolistAction('notion_investigation', 'unlink_investigation')
            ->assertNotified('Investigation link removed');

        $this->assertSame(self::CREATED_ID, $incident->fresh()->notion_investigation_page_id);
        $this->assertNotNull($incident->fresh()->notion_investigation_unlinked_at);
    }

    /**
     * Owner 2026-11-04 (round 10): the unlinked state keeps the section
     * visible with the stored copy cleared — Relink picks the page back up,
     * the unlinked note explains the state, Unlink itself is gone.
     */
    public function test_a_soft_unlinked_record_keeps_the_section_with_a_relink_action(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_unlinked_at' => '2026-11-04 00:00:00',
        ]);

        $html = Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->html();

        $this->assertStringContainsString('Notion Investigation Pages', $html);
        $this->assertStringContainsString('Relink investigation', $html);
        $this->assertStringContainsString('Unlinked from Notion', $html);
        $this->assertStringContainsString('https://www.notion.so/'.self::CREATED_ID, $html);
        $this->assertStringNotContainsString('Unlink investigation', $html);
        // The stored copy is gone — the synced line must not render either.
        $this->assertStringNotContainsString('Synced at', $html);
    }

    /** The Relink action re-imports the kept page and clears the unlinked flag. */
    public function test_the_relink_action_reimports_the_page_and_clears_the_flag(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_unlinked_at' => '2026-11-04 00:00:00',
        ]);
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->fetchEnvelope('body back from Notion'));

        Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->callInfolistAction('notion_investigation', 'refresh_investigation')
            ->assertNotified('Investigation refreshed from Notion.');

        $fresh = $incident->fresh();
        $this->assertSame('body back from Notion', $fresh->notion_investigation_markdown);
        $this->assertNull($fresh->notion_investigation_unlinked_at);
    }

    /** A deliberately unlinked record must not silently relink on open. */
    public function test_opening_the_view_page_does_not_relink_a_soft_unlinked_record(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_unlinked_at' => '2026-11-04 00:00:00',
        ]);
        $this->mock(NotionMcpClient::class)->shouldNotReceive('callToolDetailed');

        Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id]);

        $this->assertNotNull($incident->fresh()->notion_investigation_unlinked_at);
    }

    /** Owner 2026-11-04 (round 10): the synced label and timestamp render on ONE line. */
    public function test_the_synced_label_and_timestamp_render_on_one_line(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'body',
            'notion_investigation_synced_at' => '2026-11-04 14:41:00',
        ]);

        $html = Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->html();

        $this->assertStringContainsString('Synced at 2026-11-04 14:41', $html);
    }

    /** Round 7: Notion tags must translate to standard HTML — Filament's HTML sanitizer drops unknown tags WITH their content. Round 8: toggles render as collapsible sections (open by default). */
    public function test_the_section_translates_notion_tags_so_the_body_survives_the_sanitizer(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => "# Table of Content\n\n<table-of-contents color=\"gray\" />\n\n---\n\n"
                ."<toggle heading=\"h1\">\nPIC\n\n<table><tr><td>Team</td></tr></table>\n</toggle>\n\n"
                ."<toggle heading=\"h2\">\nGenerator\n\nx\n</toggle>",
        ]);

        $html = Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->html();

        // Round 12: the title is a REAL heading (prose styles it like the
        // RCA section) and the section carries the id the TOC links to.
        // Round 13: selectable content (no select-none). Round 14: the
        // flex-centered indigo traitlet survives the sanitizer too.
        $this->assertStringContainsString('<details id="notion-sec-1" class="group my-5" open><summary class="flex! cursor-pointer"><h1><span class="mr-1.5 inline-block h-0! w-0! border-y-4! border-y-transparent! border-l-[6px]! border-l-indigo-500! align-middle transition-transform group-open:rotate-90"></span>PIC</h1></summary>', $html);
        $this->assertStringContainsString('<summary class="flex! cursor-pointer"><h2 class="pl-4"><span class="mr-1.5 inline-block h-0! w-0! border-y-4! border-y-transparent! border-l-[6px]! border-l-indigo-500! align-middle transition-transform group-open:rotate-90"></span>Generator</h2></summary>', $html);
        $this->assertStringNotContainsString('select-none', $html);
        $this->assertStringContainsString('<td>Team</td>', $html);
        $this->assertStringNotContainsString('<toggle', $html);
        // Round 11/12: the rebuilt table of contents (with its links)
        // survives the pipeline.
        $this->assertStringContainsString('<li><a href="#notion-sec-1">PIC</a></li>', $html);
        $this->assertStringContainsString('<li class="pl-4"><a href="#notion-sec-2">Generator</a></li>', $html);
        // (The entry's hiddenLabel renders as a visually-hidden sr-only a11y
        // label — copying the page text includes it; that is intended.)
    }

    /** Round 9: the header carries exactly ONE Export group (a round-8 edit briefly duplicated it). */
    public function test_the_header_has_exactly_one_export_group(): void
    {
        $page = Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $this->incident()->id])
            ->instance();

        $all = collect((new ReflectionMethod($page, 'getHeaderActions'))->invoke($page))
            ->flatMap(fn ($action) => $action instanceof \Filament\Actions\ActionGroup
                ? $action->getActions()
                : [$action])
            ->map(fn ($action) => method_exists($action, 'getName') ? $action->getName() : null)
            ->filter()
            ->values();

        $this->assertSame(1, $all->countBy()->get('generate_post_mortem'), 'generate_post_mortem must appear exactly once');
        $this->assertSame(1, $all->countBy()->get('export_markdown'), 'export_markdown must appear exactly once');
    }

    /** Round 8: the Notion pair lives ONLY in the section footer — the header must not know it exists. The export pair stays grouped. */
    public function test_notion_actions_are_not_header_actions(): void
    {
        $incident = $this->incident(['notion_investigation_page_id' => self::CREATED_ID]);

        $incidentPage = Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->instance();
        $this->assertMergedIntoGroups($incidentPage, ['generate_post_mortem', 'export_markdown']);
        $this->assertNotInHeaderActions($incidentPage, ['refresh_investigation', 'unlink_investigation']);

        $issue = $this->incident([
            'classification' => IncidentClassification::Issue,
            'notion_investigation_page_id' => self::CREATED_ID,
        ]);
        $issuePage = Livewire::actingAs($this->manager('issues'))
            ->test(ViewIssue::class, ['record' => (string) $issue->id])
            ->instance();
        $this->assertNotInHeaderActions($issuePage, ['refresh_investigation', 'unlink_investigation']);
    }

    /** @param array<int, string> $expectedNames actions that must exist inside a group, not at the top level */
    private function assertMergedIntoGroups($page, array $expectedNames): void
    {
        $top = collect((new ReflectionMethod($page, 'getHeaderActions'))->invoke($page));
        // ActionGroup itself has no name — only named (non-group) triggers count as top-level buttons.
        $topNames = $top
            ->filter(fn ($action) => method_exists($action, 'getName'))
            ->map(fn ($action) => $action->getName())
            ->values()
            ->all();

        $grouped = $top
            ->filter(fn ($action) => $action instanceof \Filament\Actions\ActionGroup)
            ->flatMap(fn ($group) => $group->getActions())
            ->map(fn ($action) => $action->getName())
            ->values()
            ->all();

        foreach ($expectedNames as $name) {
            $this->assertContains($name, $grouped, "Action {$name} must live inside a group");
            $this->assertNotContains($name, $topNames, "Action {$name} must not be a top-level header button");
        }
    }

    /** @param array<int, string> $names actions that must not appear anywhere in the header (top level or grouped) */
    private function assertNotInHeaderActions($page, array $names): void
    {
        $all = collect((new ReflectionMethod($page, 'getHeaderActions'))->invoke($page))
            ->flatMap(fn ($action) => $action instanceof \Filament\Actions\ActionGroup
                ? $action->getActions()
                : [$action])
            ->map(fn ($action) => method_exists($action, 'getName') ? $action->getName() : null)
            ->filter()
            ->values()
            ->all();

        foreach ($names as $name) {
            $this->assertNotContains($name, $all, "Action {$name} must not be a header action");
        }
    }

    /** Round 9: a flagged (deleted-in-Notion) incident offers Re-investigate instead of the dead buttons. */
    public function test_a_deleted_page_replaces_the_footer_actions_with_re_investigate(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_deleted_at' => '2026-11-04 00:00:00',
        ]);

        $html = Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->html();

        $this->assertStringContainsString('Re-investigate', $html);
        $this->assertStringNotContainsString('Unlink investigation', $html);
        $this->assertStringNotContainsString('Refresh from Notion', $html);
        $this->assertStringNotContainsString('Open in Notion', $html);
    }

    /** Re-investigate is incident-only too — a flagged issue keeps its Unlink way out. */
    public function test_a_deleted_page_on_an_issue_keeps_the_unlink_action(): void
    {
        $issue = $this->incident([
            'classification' => IncidentClassification::Issue,
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_deleted_at' => '2026-11-04 00:00:00',
        ]);

        $html = Livewire::actingAs($this->manager('issues'))
            ->test(ViewIssue::class, ['record' => (string) $issue->id])
            ->html();

        $this->assertStringContainsString('Unlink investigation', $html);
        $this->assertStringNotContainsString('Re-investigate', $html);
    }

    public function test_reinvestigate_clears_the_link_and_starts_a_fresh_page(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'old body',
            'notion_investigation_deleted_at' => '2026-11-04 00:00:00',
        ]);
        Setting::set(NotionInvestigationService::PARENT_KEY, self::PARENT_ID);
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::PARENT_ID])
            ->andReturn($this->pageParentFetch())
            ->shouldReceive('callToolDetailed')->once()->with('notion-create-pages', \Mockery::any())
            ->andReturn(['structured' => ['id' => self::NEW_ID], 'text' => 'Created page.', 'isError' => false]);

        Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->callInfolistAction('notion_investigation', 'reinvestigate_investigation')
            ->assertNotified('A fresh investigation page was created — the deleted page remains in Notion\'s trash.');

        $fresh = $incident->fresh();
        $this->assertSame(self::NEW_ID, $fresh->notion_investigation_page_id);
        $this->assertNull($fresh->notion_investigation_deleted_at);
        $this->assertSame('In progress', $fresh->incident_status->value);
    }

    /** A known-deleted page must not be re-fetched on every open. */
    public function test_opening_the_view_page_does_not_refresh_a_flagged_deleted_page(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'stored body',
            'notion_investigation_synced_at' => now()->subMinutes(10),
            'notion_investigation_deleted_at' => '2026-11-04 00:00:00',
        ]);
        $this->mock(NotionMcpClient::class)->shouldNotReceive('callToolDetailed');

        Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->assertSee('stored body');
        $this->assertNotNull($incident->fresh()->notion_investigation_deleted_at);
    }

    /** Round 8: opening the view page refreshes a stale investigation copy — quietly. */
    public function test_opening_the_view_page_refreshes_a_stale_investigation(): void
    {
        $incident = $this->incident([
            'classification' => IncidentClassification::Issue,
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'stale body',
            'notion_investigation_synced_at' => now()->subMinutes(10),
        ]);
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->twice()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->fetchEnvelope('auto-refreshed body'));

        Livewire::actingAs($this->manager('issues'))
            ->test(ViewIssue::class, ['record' => (string) $incident->id])
            ->assertSee('auto-refreshed body');
        $this->assertSame('auto-refreshed body', $incident->fresh()->notion_investigation_markdown);

        $incident2 = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'stale body',
            'notion_investigation_synced_at' => now()->subMinutes(10),
        ]);

        Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident2->id])
            ->assertSee('auto-refreshed body');
        $this->assertSame('auto-refreshed body', $incident2->fresh()->notion_investigation_markdown);
    }

    /** A copy synced within the last minute is fresh — opening must not call Notion. */
    public function test_opening_the_view_page_skips_the_refresh_when_the_copy_is_fresh(): void
    {
        $incident = $this->incident([
            'classification' => IncidentClassification::Issue,
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'fresh body',
            'notion_investigation_synced_at' => now()->subSeconds(10),
        ]);
        $this->mock(NotionMcpClient::class)->shouldNotReceive('callToolDetailed');

        Livewire::actingAs($this->manager('issues'))
            ->test(ViewIssue::class, ['record' => (string) $incident->id])
            ->assertSee('fresh body');
        $this->assertSame('fresh body', $incident->fresh()->notion_investigation_markdown);
    }

    public function test_opening_the_view_page_does_not_refresh_without_a_linked_page(): void
    {
        $incident = $this->incident(['classification' => IncidentClassification::Issue]);
        $this->mock(NotionMcpClient::class)->shouldNotReceive('callToolDetailed');

        Livewire::actingAs($this->manager('issues'))
            ->test(ViewIssue::class, ['record' => (string) $incident->id]);

        $incident2 = $this->incident();
        $this->mock(NotionMcpClient::class)->shouldNotReceive('callToolDetailed');

        Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident2->id]);
    }

    /** A viewer without the manage permission must not trigger the refresh write. */
    public function test_opening_the_view_page_does_not_refresh_without_the_manage_permission(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'stale body',
            'notion_investigation_synced_at' => now()->subMinutes(10),
        ]);
        $this->mock(NotionMcpClient::class)->shouldNotReceive('callToolDetailed');

        Livewire::actingAs($this->viewer())
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->assertSee('stale body');
        $this->assertSame('stale body', $incident->fresh()->notion_investigation_markdown);
    }

    /** A failed auto-refresh renders the page with the stored copy — no error surfaces. */
    public function test_opening_the_view_page_survives_a_failed_auto_refresh(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'stored body',
            'notion_investigation_synced_at' => now()->subMinutes(10),
        ]);
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn(['structured' => null, 'isError' => false, 'text' => 'not json at all']);

        Livewire::actingAs($this->manager('incidents'))
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->assertSee('stored body');
        $this->assertSame('stored body', $incident->fresh()->notion_investigation_markdown);
    }

    /** Admin-role user (skips year-access scoping) with the given domain's manage permission. */
    private function manager(string $domain): User
    {
        // IncidentPolicy@view gates both view pages on "view incidents", so
        // every real role holds it too (RolesAndPermissionsSeeder).
        foreach (['incidents', 'issues'] as $d) {
            Role::firstOrCreate(['name' => 'admin']);
            Permission::firstOrCreate(['name' => "view {$d}"]);
            Permission::firstOrCreate(['name' => "manage {$d}"]);
        }
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo(['view incidents', 'view issues', "manage {$domain}"]);

        return $user;
    }

    /** A viewer without the manage permission (auto-refresh must not fire for them). */
    private function viewer(): User
    {
        foreach (['incidents', 'issues'] as $d) {
            Role::firstOrCreate(['name' => 'admin']);
            Permission::firstOrCreate(['name' => "view {$d}"]);
            Permission::firstOrCreate(['name' => "manage {$d}"]);
        }
        $user = User::factory()->create();
        $user->givePermissionTo(['view incidents', 'view issues']);

        return $user;
    }

    private function incident(array $overrides = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'classification' => IncidentClassification::Incident,
        ], $overrides));
    }

    /** A notion-fetch envelope whose page body is `$content`. */
    private function fetchEnvelope(string $content): array
    {
        return ['structured' => null, 'isError' => false, 'text' => json_encode([
            'metadata' => ['type' => 'page'],
            'text' => '<page url="https://app.notion.com/p/'.self::CREATED_ID.'"><content>'.$content.'</content></page>',
        ])];
    }

    /** A notion-fetch response for a plain page parent (no data-source-state). */
    private function pageParentFetch(): array
    {
        return ['structured' => null, 'isError' => false, 'text' => json_encode([
            'metadata' => ['type' => 'page'],
            'text' => '<page url="https://app.notion.com/p/'.self::PARENT_ID.'">'
                .'<content>Team pages live here.</content></page>',
        ])];
    }
}
