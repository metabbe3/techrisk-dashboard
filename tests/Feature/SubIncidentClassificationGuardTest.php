<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentClassification;
use App\Enums\Severity;
use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PROJ-022 Task 10: a record linked in a hierarchy (sub of a super, or a
 * super with subs) cannot be reclassified Incident ↔ Issue until unlinked.
 */
class SubIncidentClassificationGuardTest extends TestCase
{
    use RefreshDatabase;

    private function incident(array $attrs = []): Incident
    {
        return Incident::factory()->createQuietly(array_merge([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            // Midnight today: never future (maxDate(now())) at any hour the
            // suite runs — 10:00 today is future-dated before 10am and made
            // this test fail on the date validation, not its own subject.
            'incident_date' => now()->startOfDay(),
        ], $attrs));
    }

    private function manager(): User
    {
        Role::firstOrCreate(['name' => 'admin']);
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'manage incidents']);
        $user = User::factory()->create();
        $user->assignRole('admin'); // skips applyUserYearAccess record scoping
        $user->givePermissionTo(['view incidents', 'manage incidents']);

        return $user;
    }

    public function test_edit_form_rejects_classification_change_on_a_linked_record(): void
    {
        $super = $this->incident();
        $sub = Incident::factory()->subOf($super)->createQuietly([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
        ]);

        Livewire::actingAs($this->manager())
            ->test(EditIncident::class, ['record' => (string) $sub->id])
            ->fillForm(['classification' => 'Issue'])
            ->call('save')
            ->assertHasFormErrors(['classification']);

        $this->assertSame('Incident', $sub->fresh()->classification->value);
    }

    public function test_edit_form_still_allows_classification_change_when_unlinked(): void
    {
        $standalone = $this->incident();

        Livewire::actingAs($this->manager())
            ->test(EditIncident::class, ['record' => (string) $standalone->id])
            ->fillForm(['classification' => 'Issue'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Issue', $standalone->fresh()->classification->value);
    }

    public function test_downgrade_action_refuses_a_linked_record(): void
    {
        $super = $this->incident();
        $sub = Incident::factory()->subOf($super)->createQuietly([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
        ]);

        Livewire::actingAs($this->manager())->test(\App\Filament\Resources\IncidentResource\Pages\ListIncidents::class)
            ->call('toggleExpandedSubIncidents', $super->id)
            ->callTableAction('downgrade_to_issue', $sub)
            ->assertNotified();

        $this->assertSame('Incident', $sub->fresh()->classification->value);
    }

    public function test_bulk_downgrade_skips_linked_records_and_downgrades_the_rest(): void
    {
        $super = $this->incident();
        $sub = Incident::factory()->subOf($super)->createQuietly([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
        ]);
        $standalone = $this->incident();

        Livewire::actingAs($this->manager())->test(\App\Filament\Resources\IncidentResource\Pages\ListIncidents::class)
            ->call('toggleExpandedSubIncidents', $super->id)
            ->callTableBulkAction('bulk_downgrade', [$sub, $standalone])
            ->assertNotified();

        $this->assertSame('Incident', $sub->fresh()->classification->value);
        $this->assertSame('Issue', $standalone->fresh()->classification->value);
    }
}
