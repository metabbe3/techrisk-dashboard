<?php

namespace Tests\Feature;

use App\Filament\Actions\ActionImprovementFormSchema;
use App\Filament\Resources\ActionImprovementResource\Pages\ListActionImprovements;
use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\ActionImprovementsRelationManager;
use App\Filament\Widgets\ActionImprovementsOverview;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Status tbd (owner 2026-10-26) — the parking state "TBD / to be discussed".
 * Distinct from due_date = null ("TBD" date): a tbd row is never chased even
 * with a past due date, stays out of the widget, and reads as parked (gray).
 * Also covers cancellation_reason (mandatory when cancelling, ignored
 * otherwise) enforced in the shared form.
 */
class ActionImprovementTbdStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    // ---- reminders / escalation skip status tbd, even past due ----

    public function test_reminder_command_skips_status_tbd_rows_with_past_due_date(): void
    {
        Mail::fake();
        $pic = User::factory()->create();
        $incident = Incident::factory()->create();
        $action = $this->action($incident, [
            'pic_email' => [$pic->email],
            'reminder' => true,
            'status' => ActionImprovement::STATUS_TBD,
            'due_date' => now()->subDays(20)->startOfDay(), // parked rows are never chased
        ]);

        $this->artisan('reminders:send-action-improvements')->assertSuccessful();

        $this->assertSame(0, $action->refresh()->reminders_sent);
        $this->assertNull($action->refresh()->last_reminded_at);
        $this->assertSame(0, $action->refresh()->escalation_level);
        Mail::assertNothingSent();
    }

    // ---- widget ignores tbd rows ----

    public function test_widget_ignores_status_tbd_rows(): void
    {
        $incident = Incident::factory()->create();
        // Overdue-eligible on paper (past due), but parked → no overdue card,
        // no danger color, no TBD rider.
        $this->action($incident, [
            'status' => ActionImprovement::STATUS_TBD,
            'due_date' => now()->subDays(20)->startOfDay(),
        ]);
        // The rider counts PENDING rows without a date — this one only.
        $this->action($incident, ['due_date' => null]);

        Livewire::test(ActionImprovementsOverview::class)
            ->assertSee('1 TBD (no due date)')
            ->assertDontSee('2 TBD (no due date)')
            ->assertDontSee('danger');
    }

    // ---- cancellation_reason in the shared form ----

    public function test_cancelling_without_a_reason_is_blocked(): void
    {
        $this->actingAs($this->manager());
        $pic = User::factory()->create();
        $incident = Incident::factory()->create(['incident_status' => 'In progress']);
        $action = $this->action($incident, ['pic_email' => [$pic->email]]);

        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])
            ->callTableAction('edit', $action, [
                'title' => $action->title,
                'detail' => $action->detail,
                'due_date' => now()->addDays(10)->toDateString(),
                'pic_email' => [$pic->email],
                'reminder' => false,
                'status' => ActionImprovement::STATUS_CANCELLED,
                'cancellation_reason' => '',
            ])
            ->assertHasTableActionErrors(['cancellation_reason']);

        $this->assertSame(ActionImprovement::STATUS_PENDING, $action->refresh()->status, 'blocked save must not persist');
    }

    public function test_cancelling_with_a_reason_persists(): void
    {
        $this->actingAs($this->manager());
        $pic = User::factory()->create();
        $incident = Incident::factory()->create(['incident_status' => 'In progress']);
        $action = $this->action($incident, ['pic_email' => [$pic->email]]);

        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])
            ->callTableAction('edit', $action, [
                'title' => $action->title,
                'detail' => $action->detail,
                'due_date' => now()->addDays(10)->toDateString(),
                'pic_email' => [$pic->email],
                'reminder' => false,
                'status' => ActionImprovement::STATUS_CANCELLED,
                'cancellation_reason' => 'Mitigated by the vendor patch instead.',
            ])
            ->assertHasNoTableActionErrors();

        $action = $action->refresh();
        $this->assertSame(ActionImprovement::STATUS_CANCELLED, $action->status);
        $this->assertSame('Mitigated by the vendor patch instead.', $action->cancellation_reason);
    }

    public function test_reason_is_not_required_for_pending_or_done(): void
    {
        $this->actingAs($this->manager());
        $pic = User::factory()->create();
        $incident = Incident::factory()->create(['incident_status' => 'In progress']);
        $action = $this->action($incident, ['pic_email' => [$pic->email]]);
        $base = [
            'title' => $action->title,
            'detail' => $action->detail,
            'due_date' => now()->addDays(10)->toDateString(),
            'pic_email' => [$pic->email],
            'reminder' => false,
            'cancellation_reason' => '',
        ];

        foreach ([ActionImprovement::STATUS_PENDING, ActionImprovement::STATUS_DONE] as $status) {
            Livewire::test(ActionImprovementsRelationManager::class, [
                'ownerRecord' => $incident,
                'pageClass' => EditIncident::class,
            ])
                ->callTableAction('edit', $action, $base + ['status' => $status])
                ->assertHasNoTableActionErrors();

            $this->assertSame($status, $action->refresh()->status);
        }
    }

    // ---- tbd option in both status filters + gray badge ----

    public function test_status_filter_includes_tbd_on_both_surfaces(): void
    {
        $incident = Incident::factory()->create(['incident_status' => 'In progress']);
        $parked = $this->action($incident, ['title' => 'Parked action', 'status' => ActionImprovement::STATUS_TBD]);
        $tracked = $this->action($incident, ['title' => 'Tracked action']);

        $this->actingAs($this->viewer());
        Livewire::test(ListActionImprovements::class)
            ->set('tableFilters', ['status' => ['value' => ActionImprovement::STATUS_TBD]])
            ->assertCanSeeTableRecords([$parked])
            ->assertCanNotSeeTableRecords([$tracked]);

        $this->actingAs($this->manager());
        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])
            ->set('tableFilters', ['status' => ['value' => ActionImprovement::STATUS_TBD]])
            ->assertSee('Parked action')
            ->assertDontSee('Tracked action');
    }

    public function test_tbd_badge_is_gray(): void
    {
        $this->assertSame('gray', ActionImprovementFormSchema::statusColor(ActionImprovement::STATUS_TBD));
    }

    // ---- send_reminder hidden for parked rows ----

    public function test_send_reminder_action_hidden_for_tbd_rows(): void
    {
        $this->actingAs($this->manager());
        $incident = Incident::factory()->create(['incident_status' => 'In progress']);
        $parked = $this->action($incident, ['status' => ActionImprovement::STATUS_TBD]);
        $tracked = $this->action($incident);

        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])
            ->assertTableActionHidden('send_reminder', $parked)
            ->assertTableActionVisible('send_reminder', $tracked);
    }
}
