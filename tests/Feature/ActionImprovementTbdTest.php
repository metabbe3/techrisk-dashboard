<?php

namespace Tests\Feature;

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
 * Owner 2026-10-14: "some action improvement have TBD date or dont know can
 * we make choose TBD ?" — null due_date = TBD: choosable by clearing the
 * date picker, rendered as "TBD", and invisible to every reminder/overdue
 * surface (SQL comparisons already exclude NULL).
 */
class ActionImprovementTbdTest extends TestCase
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

    public function test_due_date_can_be_cleared_to_tbd_via_edit(): void
    {
        $this->actingAs($this->manager());
        $pic = User::factory()->create();
        $incident = Incident::factory()->create(['incident_status' => 'In progress']);
        $action = ActionImprovement::create([
            'incident_id' => $incident->id,
            'title' => 'Review DB upgrade runbook',
            'detail' => 'Update the on-call runbook.',
            'due_date' => now()->addDays(10)->toDateString(),
            'pic_email' => [$pic->email],
            'reminder' => false,
            'status' => ActionImprovement::STATUS_PENDING,
        ]);

        Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ])
            ->callTableAction('edit', $action, [
                'title' => 'Review DB upgrade runbook',
                'detail' => 'Update the on-call runbook.',
                'due_date' => null, // clearing the picker IS choosing TBD
                'pic_email' => [$pic->email],
                'reminder' => false,
                'status' => ActionImprovement::STATUS_PENDING,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertNull($action->refresh()->due_date);
    }

    public function test_tbd_row_renders_placeholder_on_global_page(): void
    {
        foreach (['manage incidents', 'view incidents', 'access dashboard'] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
        $this->actingAs(
            User::factory()->create()->givePermissionTo('manage incidents', 'view incidents', 'access dashboard')
        );
        $incident = Incident::factory()->create(['incident_status' => 'In progress']);
        ActionImprovement::create([
            'incident_id' => $incident->id,
            'title' => 'Decide the go/no-go date for the migration',
            'detail' => 'Pending the capacity review.',
            'due_date' => null,
            'pic_email' => [],
            'reminder' => false,
            'status' => ActionImprovement::STATUS_PENDING,
        ]);

        Livewire::test(ListActionImprovements::class)
            ->assertSuccessful()
            ->assertSee('TBD');
    }

    public function test_reminder_command_skips_tbd_rows(): void
    {
        Mail::fake();
        $pic = User::factory()->create();
        $incident = Incident::factory()->create();
        $action = ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => [$pic->email],
            'reminder' => true,
            'status' => 'pending',
            'due_date' => null, // TBD — never due-soon, never overdue, never escalated
        ]);

        $this->artisan('reminders:send-action-improvements')->assertSuccessful();

        $this->assertSame(0, $action->refresh()->reminders_sent);
        $this->assertNull($action->refresh()->last_reminded_at);
        $this->assertSame(0, $action->refresh()->escalation_level);
        Mail::assertNothingSent();
    }

    public function test_tbd_filter_isolates_rows_without_due_date(): void
    {
        $this->actingAs($this->manager());
        $incident = Incident::factory()->create(['incident_status' => 'In progress']);
        ActionImprovement::create($this->tbdAttrs($incident, 'Needs a date'));
        ActionImprovement::create(array_merge($this->tbdAttrs($incident), [
            'title' => 'Dated row',
            'due_date' => now()->addDays(10)->toDateString(),
        ]));

        $component = Livewire::test(ActionImprovementsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => EditIncident::class,
        ]);

        $component->set('tableFilters', ['tbd' => ['value' => true]])
            ->assertSee('Needs a date')
            ->assertDontSee('Dated row');

        // "Not overdue" must not swallow TBD rows — no date ≠ past due.
        $component->set('tableFilters', ['overdue' => ['value' => false]])
            ->assertSee('Needs a date')
            ->assertSee('Dated row');
    }

    public function test_widget_counts_pending_tbd_rows(): void
    {
        $incident = Incident::factory()->create();
        ActionImprovement::factory()->create(array_merge($this->tbdAttrs($incident), ['incident_id' => $incident->id]));
        // Draft TBD and dated pending rows don't count — tracking pending only.
        ActionImprovement::factory()->create(array_merge($this->tbdAttrs($incident), [
            'incident_id' => $incident->id,
            'status' => 'draft',
        ]));
        ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'pic_email' => [],
            'reminder' => false,
            'status' => 'pending',
            'due_date' => now()->addDays(10),
        ]);

        Livewire::test(ActionImprovementsOverview::class)
            ->assertSee('1 TBD (no due date)');
    }

    /** @return array<string, mixed> */
    private function tbdAttrs(Incident $incident, string $title = 'TBD row'): array
    {
        return [
            'incident_id' => $incident->id,
            'title' => $title,
            'detail' => 'No date decided yet.',
            'due_date' => null,
            'pic_email' => [],
            'reminder' => false,
            'status' => ActionImprovement::STATUS_PENDING,
        ];
    }
}
