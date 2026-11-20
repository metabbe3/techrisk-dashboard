<?php

namespace Tests\Feature;

use App\Filament\Resources\ActionImprovementResource\Pages\ListActionImprovements;
use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\ActionImprovementsRelationManager;
use App\Filament\Widgets\ActionImprovementsOverview;
use App\Http\Requests\Api\V1\StoreActionImprovementRequest;
use App\Jobs\GenerateActionSuggestionsJob;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\User;
use App\Services\Ai\ActionSuggestionService;
use App\Services\Ai\AiTextService;
use App\Services\Markdown\IncidentMarkdownExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PROJ-014: closeout stamping, priority/cancelled semantics, the global
 * Action Improvements page, dashboard aging stats, and the AI suggest flow.
 */
class ActionImprovementLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $closer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->closer = User::factory()->create();
    }

    private function incident(array $attrs = []): Incident
    {
        // incident_status has no factory default, and the markdown exporter
        // (AI suggestion context) reads incident_status->value unguarded.
        return Incident::factory()->create(array_merge([
            'incident_status' => 'In progress',
        ], $attrs));
    }

    private function action(array $attrs = []): ActionImprovement
    {
        return ActionImprovement::factory()->create(array_merge([
            'incident_id' => $this->incident()->id,
            'pic_email' => [],
            'reminder' => false,
            'status' => 'pending',
            'due_date' => now()->addWeeks(2)->startOfDay(),
        ], $attrs));
    }

    // ---- Wave 1: closeout stamping ----

    public function test_transition_to_done_stamps_completed_at_and_by(): void
    {
        $this->actingAs($this->closer);
        $action = $this->action();

        $action->update(['status' => 'done', 'result_note' => 'Fixed and verified']);

        $action = $action->refresh();
        $this->assertSame('done', $action->status);
        $this->assertNotNull($action->completed_at);
        $this->assertSame($this->closer->id, $action->completed_by);
        $this->assertSame('Fixed and verified', $action->result_note);

        // Re-saving a done row does not re-stamp.
        $stamp = $action->completed_at;
        $this->travel(1)->hours();
        $action->update(['result_note' => 'More detail']);
        $this->assertTrue($stamp->equalTo($action->refresh()->completed_at));
    }

    public function test_reopening_clears_the_closeout_stamp(): void
    {
        $this->actingAs($this->closer);
        $action = $this->action();
        $action->update(['status' => 'done']);
        $this->assertNotNull($action->refresh()->completed_at);

        $action->update(['status' => 'pending']);

        $this->assertNull($action->refresh()->completed_at);
        $this->assertNull($action->refresh()->completed_by);
    }

    public function test_reminder_recording_does_not_stamp_a_closeout(): void
    {
        $action = $this->action();
        $action->recordReminderSent(1);

        $this->assertNull($action->refresh()->completed_at, 'saveQuietly must not run observer stamping');
        $this->assertSame(1, $action->reminders_sent);
    }

    // ---- Wave 1: priority + cancelled semantics ----

    public function test_priority_defaults_to_low_and_cancelled_leaves_reminders(): void
    {
        $action = $this->action();
        $this->assertSame('low', $action->refresh()->priority, 'DB default low (in-memory copy predates the insert)');

        // Cancelled rows never enter the reminder batch (status = pending gate).
        $cancelled = $this->action(['status' => 'cancelled', 'reminder' => true, 'due_date' => now()->subDays(20)->startOfDay()]);

        $this->artisan('reminders:send-action-improvements')->assertSuccessful();

        $cancelled = $cancelled->refresh();
        $this->assertSame(0, $cancelled->reminders_sent);
        $this->assertNull($cancelled->last_reminded_at);
    }

    /**
     * The write API routes are disabled by security decision (read-only API);
     * validate the request rules directly so cancelled+priority are covered
     * whenever writes are re-enabled.
     */
    public function test_api_request_rules_accept_cancelled_and_priority(): void
    {
        $base = [
            'title' => 'API action',
            'detail' => 'Created via API',
            'due_date' => now()->addDays(10)->toDateString(),
            'pic_email' => ['pic@example.com'],
        ];

        $valid = Validator::make($base + ['status' => 'cancelled', 'priority' => 'critical'],
            (new StoreActionImprovementRequest)->rules());
        $this->assertFalse($valid->fails());

        // tbd (parking status, owner 2026-10-26) passes with a nullable reason.
        $tbd = Validator::make($base + ['status' => 'tbd', 'cancellation_reason' => null],
            (new StoreActionImprovementRequest)->rules());
        $this->assertFalse($tbd->fails());

        $invalid = Validator::make($base + ['status' => 'delayed', 'priority' => 'urgent'],
            (new StoreActionImprovementRequest)->rules());
        $this->assertTrue($invalid->fails());
        $this->assertEqualsCanonicalizing(['status', 'priority'], $invalid->errors()->keys());
    }

    // ---- Wave 2: global page ----

    public function test_global_page_lists_actions_with_year_access(): void
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'access dashboard']);
        $user = User::factory()->create()->givePermissionTo('view incidents', 'access dashboard');
        $this->actingAs($user);

        $mine = $this->action(['title' => 'Visible action']);
        $other = $this->action(['title' => 'Hidden action']);
        $other->incident->update(['incident_date' => now()->subYears(3)]);

        Livewire::test(ListActionImprovements::class)
            ->assertSuccessful()
            ->assertSee('Visible action')
            ->assertDontSee('Hidden action');
    }

    public function test_global_page_overdue_filter_and_escalation_column(): void
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'access dashboard']);
        $user = User::factory()->create()->givePermissionTo('view incidents', 'access dashboard');
        $this->actingAs($user);

        $escalated = $this->action(['title' => 'Escalated action', 'due_date' => now()->subDays(20)->startOfDay()]);
        $escalated->forceFill(['escalation_level' => 2, 'last_escalated_at' => now()])->saveQuietly();
        $future = $this->action(['title' => 'Future action']);

        Livewire::test(ListActionImprovements::class)
            ->set('tableFilters', ['overdue' => ['value' => true]])
            ->assertCanSeeTableRecords([$escalated])
            ->assertCanNotSeeTableRecords([$future]);

        // Escalation badge renders for escalated rows only.
        Livewire::test(ListActionImprovements::class)->assertSee('L2');
    }

    // ---- PROJ-015: incident visibility + grouping + comprehensive table ----

    private function viewer(): User
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'access dashboard']);

        return User::factory()->create()->givePermissionTo('view incidents', 'access dashboard');
    }

    public function test_global_page_groups_by_incident_by_default(): void
    {
        $this->actingAs($this->viewer());
        $incident = $this->incident(['no' => '2026_IN_P2_042', 'title' => 'Payment gateway timeout']);
        $this->action(['incident_id' => $incident->id, 'title' => 'Add retry policy']);

        // Default view = grouped by incident: heading carries no + title.
        Livewire::test(ListActionImprovements::class)
            ->assertSee('2026_IN_P2_042 — Payment gateway timeout')
            ->assertSee('Add retry policy')
            ->assertSee('Incident No');
    }

    public function test_incident_groups_default_to_latest_first(): void
    {
        $this->actingAs($this->viewer());
        // Newest incident deliberately carries the LOWER "no" — proves the
        // groups order by incident_date, not the "no" string.
        $newest = $this->incident(['no' => '2026_IN_P1_100', 'title' => 'September outage', 'incident_date' => '2026-09-30']);
        $oldest = $this->incident(['no' => '2026_IN_P1_900', 'title' => 'January glitch', 'incident_date' => '2026-01-05']);
        $this->action(['incident_id' => $newest->id]);
        $this->action(['incident_id' => $oldest->id]);

        Livewire::test(ListActionImprovements::class)
            ->assertSet('tableGroupingDirection', 'desc')
            ->assertSeeInOrder(['September outage', 'January glitch']);
    }

    public function test_global_page_searches_across_incident_fields(): void
    {
        $this->actingAs($this->viewer());
        $hit = $this->action(['title' => 'Add retry policy']);
        $hit->incident->update(['title' => 'Payment gateway timeout saga']);
        $miss = $this->action(['title' => 'Unrelated action']);
        $miss->incident->update(['title' => 'Database corruption']);

        Livewire::test(ListActionImprovements::class)
            ->set('tableSearch', 'gateway timeout')
            ->assertCanSeeTableRecords([$hit])
            ->assertCanNotSeeTableRecords([$miss]);
    }

    public function test_global_page_groups_by_pic_combo(): void
    {
        $this->actingAs($this->viewer());
        $assigned = $this->action(['pic_email' => ['ana@x.id', 'budi@x.id']]);
        $unassigned = $this->action(['pic_email' => []]);

        Livewire::test(ListActionImprovements::class)
            ->set('tableGrouping', 'pic_email')
            ->assertSee('ana@x.id, budi@x.id')
            ->assertSee('Unassigned')
            ->assertCanSeeTableRecords([$assigned, $unassigned]);
    }

    public function test_global_page_comprehensive_columns_render(): void
    {
        $viewer = $this->viewer();
        $this->actingAs($viewer); // observer stamps completed_by = this user
        $done = $this->action(['status' => 'done']);
        $done->forceFill([
            'result_note' => 'Verified by reconciliation report',
            'last_reminded_at' => now()->subDays(2),
            'reminders_sent' => 3,
        ])->saveQuietly();

        Livewire::test(ListActionImprovements::class)
            // result_note + closer name show under Completed (visible by default).
            ->assertSee('Verified by reconciliation report')
            ->assertSee($viewer->name)
            // An empty toggle map shows every toggleable column.
            ->set('toggledTableColumns', [])
            ->assertSee('Last email')
            ->assertSee('3 reminder(s) emailed');
    }

    public function test_relation_manager_renders_new_fields_and_filters(): void
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'access dashboard']);
        $user = User::factory()->create()->givePermissionTo('view incidents', 'access dashboard');
        $this->actingAs($user);

        $incident = Incident::factory()->create();
        $this->action(['incident_id' => $incident->id, 'title' => 'Rel manager row', 'priority' => 'critical']);

        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])
            ->assertSuccessful()
            ->assertSee('Rel manager row')
            ->assertSee('critical');
    }

    // ---- Wave 3: dashboard aging widget ----

    public function test_dashboard_widget_aging_and_closeout_stats(): void
    {
        $this->action(); // one healthy pending row, no closeouts yet

        Livewire::test(ActionImprovementsOverview::class)
            ->assertSee('Overdue Action Improvements')
            ->assertSee('Pending past their due date')
            ->assertSee('—');

        // Array cache is per-test; flush so the second render recomputes.
        Cache::flush();

        $done = $this->action(['status' => 'done']);
        $done->forceFill(['created_at' => now()->subDays(10), 'completed_at' => now()->subDays(3)])->saveQuietly();

        $overdue = $this->action(['due_date' => now()->subDays(20)->startOfDay()]);
        $overdue->forceFill(['escalation_level' => 1])->saveQuietly();

        Livewire::test(ActionImprovementsOverview::class)
            ->assertSee('1 escalated to a manager')
            ->assertSee('7'); // avg days to close: 10 − 3
    }

    // ---- Wave 4: AI suggest ----

    private function makeService(array $aiResult): ActionSuggestionService
    {
        $ai = $this->mock(AiTextService::class, function (MockInterface $mock) use ($aiResult) {
            $mock->shouldReceive('callAiForJson')->andReturn($aiResult);
        });

        return new ActionSuggestionService($ai, app(IncidentMarkdownExporter::class), app(\App\Services\ActionImprovements\DocumentActionItemService::class));
    }

    public function test_suggest_actions_creates_deduped_drafts(): void
    {
        $incident = $this->incident();
        $this->action([
            'incident_id' => $incident->id,
            'title' => 'Add rate limiting to the API',
        ]);

        $service = $this->makeService(['actions' => [
            ['title' => 'Add rate limiting to the API', 'detail' => 'Duplicate of existing', 'priority' => 'high'],
            ['title' => 'Add paging alerts', 'detail' => 'Detect threshold breaches within 5 minutes', 'priority' => 'critical'],
            ['title' => '', 'detail' => 'Garbage empty title'],
            ['title' => 'Review runbook', 'detail' => 'Update the on-call runbook', 'priority' => 'bogus'],
        ]]);

        $created = $service->generate($incident);

        $this->assertSame(2, $created);
        $drafts = ActionImprovement::where('status', 'draft')->orderBy('id')->get();
        $this->assertSame(['Add paging alerts', 'Review runbook'], $drafts->pluck('title')->all());
        $this->assertSame('critical', $drafts[0]->priority, 'AI priority kept when valid');
        $this->assertSame('low', $drafts[1]->priority, 'invalid priority falls back to low');
        $this->assertSame($incident->id, $drafts[0]->incident_id);
        // No date in the AI output → TBD, no invented +30d (owner 2026-10-14).
        $this->assertNull($drafts[0]->due_date);

        // Re-running proposes the same set → 0 new rows (title dedupe).
        $this->assertSame(0, $service->generate($incident));
    }

    public function test_suggest_actions_job_notifies_the_requester(): void
    {
        $incident = $this->incident();
        $this->mock(AiTextService::class, function (MockInterface $mock) {
            $mock->shouldReceive('callAiForJson')
                ->andReturn(['actions' => [['title' => 'Patch the dependency', 'detail' => 'Upgrade to the fixed release', 'priority' => 'high']]]);
        });

        (new GenerateActionSuggestionsJob($incident, $this->closer))
            ->handle(app(ActionSuggestionService::class));

        $rows = DB::table('notifications')
            ->where('notifiable_id', $this->closer->id)
            ->where('data', 'like', '%draft action item%')
            ->count();
        $this->assertSame(1, $rows);
        $this->assertSame(1, ActionImprovement::where('status', 'draft')->count());
    }
}
