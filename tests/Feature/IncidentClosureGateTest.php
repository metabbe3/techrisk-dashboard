<?php

namespace Tests\Feature;

use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PROJ-011 Wave 2 (RD-2): a row cannot move INTO Completed without RCA and
 * stop-bleed — one guard in IncidentObserver::saving() covers the edit
 * form, update_status, API and imports, because they all save the model.
 */
class IncidentClosureGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function openIncident(array $attrs = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'classification' => 'Incident',
            'severity' => 'P1',
            'incident_status' => 'Open',
            'incident_date' => now()->subDays(5)->setTime(9, 0),
        ], $attrs));
    }

    public function test_transition_to_completed_is_blocked_without_rca(): void
    {
        $incident = $this->openIncident(['stop_bleeding_at' => now()->subDays(5)->setTime(10, 0)]);

        try {
            $incident->update(['incident_status' => 'Completed']);
            $this->fail('The closure gate did not throw.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('incident_status', $e->errors());
        }

        $this->assertSame('Open', $incident->fresh()->incident_status?->value);
    }

    public function test_transition_to_completed_is_blocked_without_stop_bleeding(): void
    {
        $incident = $this->openIncident(['root_cause' => 'bug in X']);

        $this->expectException(ValidationException::class);
        $incident->update(['incident_status' => 'Completed']);
    }

    public function test_root_cause_category_satisfies_the_gate(): void
    {
        $incident = $this->openIncident([
            'root_cause_category' => ['Human Error'],
            'stop_bleeding_at' => now()->subDays(5)->setTime(10, 0),
        ]);

        $incident->update(['incident_status' => 'Completed']);

        $this->assertSame('Completed', $incident->fresh()->incident_status?->value);
    }

    public function test_na_root_cause_is_a_deliberate_human_override(): void
    {
        $incident = $this->openIncident([
            'root_cause' => 'N/A',
            'stop_bleeding_at' => now()->subDays(5)->setTime(10, 0),
        ]);

        $incident->update(['incident_status' => 'Completed']);

        $this->assertSame('Completed', $incident->fresh()->incident_status?->value);
    }

    public function test_creating_a_row_directly_as_completed_is_gated_too(): void
    {
        $this->expectException(ValidationException::class);

        Incident::factory()->create([
            'classification' => 'Incident',
            'severity' => 'P1',
            'incident_status' => 'Completed',
            'incident_date' => now()->subDays(5)->setTime(9, 0),
            'stop_bleeding_at' => now()->subDays(5)->setTime(10, 0),
        ]);
    }

    public function test_legacy_completed_rows_stay_editable(): void
    {
        // The 28/66 pre-gate rows: no RCA, no stop-bleed, already Completed.
        // Editing OTHER fields must keep working — only the transition in
        // is gated.
        $legacy = Incident::factory()->createQuietly([
            'classification' => 'Incident',
            'severity' => 'P1',
            'incident_status' => 'Completed',
            'fund_loss' => 1000,
        ]);

        $legacy->update(['fund_loss' => 2000, 'title' => $legacy->title.' (edited)']);

        $this->assertSame(2000.0, (float) $legacy->fresh()->fund_loss);
    }

    public function test_moving_away_and_back_through_completed_is_gated(): void
    {
        $legacy = Incident::factory()->createQuietly([
            'classification' => 'Incident',
            'severity' => 'P1',
            'incident_status' => 'Completed',
        ]);

        $legacy->update(['incident_status' => 'Finalization']); // fine, leaving
        $legacy->update(['incident_status' => 'Open']);

        $this->expectException(ValidationException::class);
        $legacy->update(['incident_status' => 'Completed']); // re-entering: gated
    }

    /**
     * BUG-035: the observer's ValidationException carries the bare key
     * 'incident_status', but Filament renders errors by state path
     * (data.incident_status / mountedTableActionsData.0.incident_status) —
     * the save rolled back with NO visible message. The form must surface it.
     */
    private function manager(): User
    {
        foreach (['view incidents', 'manage incidents'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }

        return User::factory()->create()->givePermissionTo('view incidents', 'manage incidents');
    }

    public function test_edit_page_shows_the_gate_error_on_the_status_field(): void
    {
        $incident = $this->openIncident(['root_cause' => 'rc', 'stop_bleeding_at' => null]);

        Livewire::actingAs($this->manager())
            ->test(EditIncident::class, ['record' => $incident->getRouteKey()])
            ->fillForm(['incident_status' => 'Completed'])
            ->call('save')
            ->assertHasFormErrors(['incident_status']);

        $this->assertSame('Open', $incident->fresh()->incident_status?->value);
    }

    public function test_edit_page_completes_when_prerequisites_are_filled(): void
    {
        $incident = $this->openIncident(['root_cause' => null, 'stop_bleeding_at' => null]);

        Livewire::actingAs($this->manager())
            ->test(EditIncident::class, ['record' => $incident->getRouteKey()])
            ->fillForm([
                'incident_status' => 'Completed',
                'root_cause' => 'bug in X',
                'stop_bleeding_at' => now()->subDays(5)->setTime(10, 0)->format('Y-m-d H:i:s'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Completed', $incident->fresh()->incident_status?->value);
    }

    public function test_update_status_action_shows_the_gate_error(): void
    {
        $incident = $this->openIncident(['root_cause' => null, 'stop_bleeding_at' => null]);

        Livewire::actingAs($this->manager())
            ->test(ListIncidents::class)
            ->callTableAction('update_status', $incident, [
                'incident_status' => 'Completed',
                'remark' => 'closing',
            ])
            ->assertHasTableActionErrors(['incident_status']);

        $this->assertSame('Open', $incident->fresh()->incident_status?->value);
    }

    public function test_gate_message_names_the_missing_prerequisite(): void
    {
        $incident = $this->openIncident(['root_cause' => 'rc']); // stop-bleed missing

        try {
            $incident->update(['incident_status' => 'Completed']);
            $this->fail('The closure gate did not throw.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('stop bleeding', $e->errors()['incident_status'][0]);
        }
    }
}
