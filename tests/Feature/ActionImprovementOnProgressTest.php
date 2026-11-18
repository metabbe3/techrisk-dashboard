<?php

namespace Tests\Feature;

use App\Filament\Actions\ActionImprovementFormSchema;
use App\Filament\Resources\ActionImprovementResource\Pages\ListActionImprovements;
use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\ActionImprovementsRelationManager;
use App\Filament\Widgets\ActionImprovementsOverview;
use App\Mail\GroupNotificationMail;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Status on_progress (owner 2026-11-17): accepted AND started, not finished.
 * Rule A — still chased: it rides every pending-gated surface (reminders,
 * escalation, overdue tiles, KPI, AI context). Only done/cancelled/tbd stop
 * the chasing. Date-TBD semantics extend to open on_progress rows: tracked,
 * never chased — there is no date to chase against.
 */
class ActionImprovementOnProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'log']);
    }

    private function manager(): User
    {
        Permission::firstOrCreate(['name' => 'manage incidents']);

        return User::factory()->create()->givePermissionTo('manage incidents');
    }

    private function viewer(): User
    {
        foreach (['view incidents', 'access dashboard'] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        return User::factory()->create()->givePermissionTo('view incidents', 'access dashboard');
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function action(Incident $incident, array $attrs = []): ActionImprovement
    {
        return ActionImprovement::factory()->create(array_merge([
            'incident_id' => $incident->id,
            'pic_email' => [],
            'reminder' => false,
            'status' => ActionImprovement::STATUS_PENDING,
            'due_date' => now()->addWeeks(2)->startOfDay(),
        ], $attrs));
    }

    /** PIC whose email resolves to a users-table row with a 1-rung manager chain. */
    private function picWithManager(): array
    {
        $manager = User::factory()->create();
        $pic = User::factory()->create(['manager_id' => $manager->id]);

        return [$pic, $manager];
    }

    // ---- Rule A: reminders chase on_progress rows ----

    public function test_reminder_command_chases_overdue_on_progress_rows(): void
    {
        Mail::fake();
        [$pic] = $this->picWithManager();
        $incident = Incident::factory()->create();
        $overdue = $this->action($incident, [
            'title' => 'Started but overdue',
            'pic_email' => [$pic->email],
            'reminder' => true,
            'status' => ActionImprovement::STATUS_ON_PROGRESS,
            'due_date' => now()->subDays(10)->startOfDay(),
        ]);

        $this->artisan('reminders:send-action-improvements')->assertSuccessful();

        $this->assertSame(1, $overdue->refresh()->reminders_sent);
        $this->assertNotNull($overdue->refresh()->last_reminded_at);
        Mail::assertSent(GroupNotificationMail::class, 1);
    }

    public function test_on_progress_row_21_days_overdue_escalates(): void
    {
        Mail::fake();
        [$pic, $manager] = $this->picWithManager();
        $incident = Incident::factory()->create();
        $started = $this->action($incident, [
            'pic_email' => [$pic->email],
            'reminder' => true,
            'status' => ActionImprovement::STATUS_ON_PROGRESS,
            'due_date' => now()->subDays(21)->startOfDay(), // L1 at 14 days
        ]);

        $this->artisan('reminders:send-action-improvements')->assertSuccessful();

        $this->assertSame(1, $started->refresh()->escalation_level, 'started work past its due date must escalate like pending');
        $this->assertSame(1, $manager->notifications()->where('type', \App\Notifications\ActionImprovementEscalated::class)->count());
    }

    public function test_on_progress_row_without_due_date_is_not_chased(): void
    {
        Mail::fake();
        [$pic] = $this->picWithManager();
        $incident = Incident::factory()->create();
        $dateless = $this->action($incident, [
            'pic_email' => [$pic->email],
            'reminder' => true,
            'status' => ActionImprovement::STATUS_ON_PROGRESS,
            'due_date' => null,
        ]);

        $this->artisan('reminders:send-action-improvements')->assertSuccessful();

        $this->assertSame(0, $dateless->refresh()->reminders_sent);
        Mail::assertNothingSent();
    }

    // ---- Rule A: widget tiles count on_progress as open work ----

    private function widgetValues(ActionImprovementsOverview $widget): \Illuminate\Support\Collection
    {
        $stats = \Closure::bind(fn () => $this->getStats(), $widget, ActionImprovementsOverview::class)();

        return collect($stats)->map(fn ($stat) => $stat->getValue())->values();
    }

    public function test_widget_counts_on_progress_in_pending_and_overdue_tiles(): void
    {
        $incident = Incident::factory()->create();
        $this->action($incident); // pending, future due
        $this->action($incident, ['status' => ActionImprovement::STATUS_ON_PROGRESS]); // started, future due
        $this->action($incident, [
            'status' => ActionImprovement::STATUS_ON_PROGRESS,
            'due_date' => now()->subDays(5)->startOfDay(),
        ]); // started AND late
        $this->action($incident, ['status' => ActionImprovement::STATUS_DONE]);

        $values = $this->widgetValues(app(ActionImprovementsOverview::class));

        $this->assertSame(3, $values[0], 'pending tile = pending + on_progress (open work)');
        $this->assertSame(1, $values[1], 'overdue tile must count started-but-late rows');
        $this->assertSame(1, $values[2]);
    }

    public function test_widget_tbd_rider_covers_open_on_progress_rows_without_date(): void
    {
        $incident = Incident::factory()->create();
        $this->action($incident, [
            'status' => ActionImprovement::STATUS_ON_PROGRESS,
            'due_date' => null,
        ]);

        Livewire::actingAs($this->viewer())
            ->test(ActionImprovementsOverview::class)
            ->assertSee('1 TBD (no due date)');
    }

    // ---- shared form: option + badge color ----

    public function test_status_select_offers_on_progress(): void
    {
        $options = ActionImprovementFormSchema::statusOptions();

        $this->assertArrayHasKey(ActionImprovement::STATUS_ON_PROGRESS, $options);
        $this->assertSame('On progress', $options[ActionImprovement::STATUS_ON_PROGRESS]);
    }

    public function test_on_progress_badge_is_blue(): void
    {
        $this->assertSame('info', ActionImprovementFormSchema::statusColor(ActionImprovement::STATUS_ON_PROGRESS));
    }

    // ---- filters: on_progress rows reachable on both surfaces ----

    public function test_status_filter_finds_on_progress_rows_on_both_surfaces(): void
    {
        $incident = Incident::factory()->create(['incident_status' => 'In progress']);
        $started = $this->action($incident, ['title' => 'Started action', 'status' => ActionImprovement::STATUS_ON_PROGRESS]);
        $plain = $this->action($incident, ['title' => 'Pending action']);

        $this->actingAs($this->viewer());
        Livewire::test(ListActionImprovements::class)
            ->set('tableFilters', ['status' => ['value' => ActionImprovement::STATUS_ON_PROGRESS]])
            ->assertCanSeeTableRecords([$started])
            ->assertCanNotSeeTableRecords([$plain]);

        $this->actingAs($this->manager());
        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])
            ->set('tableFilters', ['status' => ['value' => ActionImprovement::STATUS_ON_PROGRESS]])
            ->assertSee('Started action')
            ->assertDontSee('Pending action');
    }

    public function test_overdue_ternary_counts_started_late_rows_on_both_surfaces(): void
    {
        $incident = Incident::factory()->create(['incident_status' => 'In progress']);
        $late = $this->action($incident, [
            'title' => 'Started and late',
            'status' => ActionImprovement::STATUS_ON_PROGRESS,
            'due_date' => now()->subDays(5)->startOfDay(),
        ]);
        $fresh = $this->action($incident, ['title' => 'Started on time', 'status' => ActionImprovement::STATUS_ON_PROGRESS]);

        $this->actingAs($this->viewer());
        Livewire::test(ListActionImprovements::class)
            ->set('tableFilters', ['overdue' => ['isActive' => true, 'value' => true]])
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$fresh]);

        $this->actingAs($this->manager());
        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])
            ->set('tableFilters', ['overdue' => ['isActive' => true, 'value' => true]])
            ->assertSee('Started and late')
            ->assertDontSee('Started on time');
    }

    // ---- send_reminder visible for started rows (still chased manually) ----

    public function test_send_reminder_action_visible_for_on_progress_rows(): void
    {
        $this->actingAs($this->manager());
        $incident = Incident::factory()->create(['incident_status' => 'In progress']);
        $started = $this->action($incident, ['status' => ActionImprovement::STATUS_ON_PROGRESS]);

        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])
            ->assertTableActionVisible('send_reminder', $started);
    }

    // ---- governance KPI counts started overdue rows ----

    public function test_governance_overdue_kpi_counts_on_progress_rows(): void
    {
        $incident = Incident::factory()->create();
        $this->action($incident, [
            'status' => ActionImprovement::STATUS_ON_PROGRESS,
            'due_date' => now()->subDays(3)->startOfDay(),
        ]);
        $this->action($incident, ['due_date' => now()->subDays(3)->startOfDay()]); // pending, overdue
        $this->action($incident, ['status' => ActionImprovement::STATUS_DONE]);

        $service = new \App\Services\Governance\GovernanceKpiService;

        $this->assertSame(2, $service->overdueActionItemCount());
    }

    // ---- AI context lists started overdue rows ----

    public function test_ai_executive_context_lists_overdue_on_progress_rows(): void
    {
        $incident = Incident::factory()->create();
        $this->action($incident, [
            'title' => 'Started and late',
            'status' => ActionImprovement::STATUS_ON_PROGRESS,
            'due_date' => now()->subDay()->startOfDay(),
        ]);

        $service = app(\App\Services\Ai\ChatContextService::class);

        // The summary surface counts the row; the risk surface lists it.
        $summary = \Closure::bind(
            fn () => $this->getExecutiveSummaryContext(),
            $service,
            \App\Services\Ai\ChatContextService::class,
        )();
        $this->assertStringContainsString('Overdue action improvements: 1', $summary);

        $risk = \Closure::bind(
            fn () => $this->getRiskContext(),
            $service,
            \App\Services\Ai\ChatContextService::class,
        )();
        $this->assertStringContainsString('### Overdue Actions (1)', $risk);
        $this->assertStringContainsString('Started and late', $risk);
    }
}
