<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Severity;
use App\Filament\Resources\IncidentResource\Pages\CreateIncident;
use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Filament\Resources\IncidentResource\Pages\ViewIncident;
use App\Jobs\CalculateIncidentMetrics;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PROJ-022 Task 7: create-sub / link-existing / unlink actions — the link
 * picker offers standalone Incidents only, the submit re-validates
 * linkability, unlink keeps both records.
 */
class SubIncidentLinkActionsTest extends TestCase
{
    use RefreshDatabase;

    private function incident(array $attrs = []): Incident
    {
        return Incident::factory()->createQuietly(array_merge([
            'classification' => \App\Enums\IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'incident_date' => now()->startOfDay()->addHours(10),
        ], $attrs));
    }

    private function manager(): User
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'manage incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo(['view incidents', 'manage incidents']);

        return $user;
    }

    public function test_links_standalone_then_moves_it_to_another_super_after_unlink(): void
    {
        $standalone = $this->incident();
        $superA = $this->incident();
        $superB = $this->incident();

        Livewire::actingAs($this->manager())->test(ListIncidents::class)
            ->callTableAction('link_sub_incident', $superA, ['sub_incident_id' => $standalone->id]);
        $this->assertSame($superA->id, $standalone->fresh()->parent_incident_id);

        // A linked sub is not pickable (one level deep) — re-link = unlink first.
        // Row actions on a SUB record need the family expanded: the table query
        // hides subs in the default view, and Filament resolves the record
        // through that query.
        $standalone->refresh();
        Livewire::actingAs($this->manager())->test(ListIncidents::class)
            ->call('toggleExpandedSubIncidents', $superA->id)
            ->callTableAction('unlink_sub_incident', $standalone);
        $this->assertNull($standalone->fresh()->parent_incident_id);

        Livewire::actingAs($this->manager())->test(ListIncidents::class)
            ->callTableAction('link_sub_incident', $superB, ['sub_incident_id' => $standalone->id]);
        $this->assertSame($superB->id, $standalone->fresh()->parent_incident_id);
        $this->assertSame(0, $superA->fresh()->subIncidents()->count());
        $this->assertSame(1, $superB->fresh()->subIncidents()->count());
    }

    public function test_rejects_linking_a_super_or_an_existing_sub(): void
    {
        $superA = $this->incident();
        $sub = Incident::factory()->subOf($superA)->createQuietly([
            'classification' => \App\Enums\IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
        ]);
        $superB = $this->incident();

        Livewire::actingAs($this->manager())->test(ListIncidents::class)
            ->callTableAction('link_sub_incident', $superB, ['sub_incident_id' => $superA->id])
            ->assertHasTableActionErrors(['sub_incident_id']);
        $this->assertSame(1, $superA->fresh()->subIncidents()->count());

        Livewire::actingAs($this->manager())->test(ListIncidents::class)
            ->callTableAction('link_sub_incident', $superB, ['sub_incident_id' => $sub->id])
            ->assertHasTableActionErrors(['sub_incident_id']);
        $this->assertSame($superA->id, $sub->fresh()->parent_incident_id);
    }

    public function test_unlink_keeps_both_records_and_dispatches_recalc(): void
    {
        $super = $this->incident();
        $sub = Incident::factory()->subOf($super)->createQuietly([
            'classification' => \App\Enums\IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
        ]);

        Bus::fake([CalculateIncidentMetrics::class]);

        Livewire::actingAs($this->manager())->test(ListIncidents::class)
            ->call('toggleExpandedSubIncidents', $super->id)
            ->callTableAction('unlink_sub_incident', $sub);

        Bus::assertDispatchedTimes(CalculateIncidentMetrics::class, 1);
        $this->assertNotNull(Incident::find($super->id));
        $this->assertNotNull(Incident::find($sub->id));
        $this->assertNull($sub->fresh()->parent_incident_id);
    }

    public function test_create_page_carries_the_parent_param(): void
    {
        $super = $this->incident();

        $component = Livewire::withQueryParams(['parent' => (string) $super->id])
            ->actingAs($this->manager())
            ->test(CreateIncident::class);

        $component->assertSet('data.parent_incident_id', $super->id)
            ->assertSee('Recording as a sub-incident of');
    }

    public function test_link_actions_hidden_without_manage_permission(): void
    {
        $super = $this->incident();
        $standalone = $this->incident();

        Permission::firstOrCreate(['name' => 'view incidents']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('view incidents');

        Livewire::actingAs($viewer)->test(ListIncidents::class)
            ->assertTableActionHidden('link_sub_incident', $super)
            ->assertTableActionHidden('create_sub_incident', $super)
            ->assertTableActionHidden('unlink_sub_incident', $standalone);

        Livewire::actingAs($this->manager())->test(ListIncidents::class)
            ->assertTableActionVisible('link_sub_incident', $super)
            ->assertTableActionVisible('create_sub_incident', $super);
    }

    public function test_view_page_header_action_links_a_sub(): void
    {
        $super = $this->incident();
        $standalone = $this->incident();

        Livewire::actingAs($this->manager())
            ->test(ViewIncident::class, ['record' => (string) $super->id])
            ->callAction('link_sub_incident', ['sub_incident_id' => $standalone->id]);

        $this->assertSame($super->id, $standalone->fresh()->parent_incident_id);
    }
}
