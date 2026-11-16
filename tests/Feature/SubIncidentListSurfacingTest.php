<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentClassification;
use App\Enums\Severity;
use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PROJ-022: the incidents list hides sub-incidents unless the user expands
 * their super (chevron) — or an active search / filter / tab / drill-in
 * surfaces them as normal rows.
 */
class SubIncidentListSurfacingTest extends TestCase
{
    use RefreshDatabase;

    private function seedFamily(): array
    {
        $super = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'title' => 'Super QK9 family head',
            'incident_date' => now()->startOfDay()->addHours(10),
        ]);

        $sub = Incident::factory()->subOf($super)->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'title' => 'Sub ZX7 hidden child',
            'incident_date' => now()->startOfDay()->addHours(10),
        ]);

        return [$super, $sub];
    }

    private function viewer(): User
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo('view incidents');

        return $user;
    }

    public function test_expand_chevron_renders_and_the_column_action_expands_the_family(): void
    {
        [$super, $sub] = $this->seedFamily();

        $component = Livewire::actingAs($this->viewer())
            ->test(ListIncidents::class);

        // Review C1: the chevron must actually resolve an icon — the expand
        // cell was an empty invisible button before (null state never called
        // the icon closure).
        $component->assertSee('M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25'); // heroicon chevron path data

        // Review C1: the real click path — the column action behind the
        // chevron's wire:click, not the backing method directly.
        $component->call('callTableColumnAction', 'expand_sub_incidents', (string) $super->id)
            ->assertSee('Sub ZX7 hidden child');
    }

    public function test_default_view_hides_sub_rows_and_expansion_reveals_them(): void
    {
        [$super, $sub] = $this->seedFamily();

        Livewire::actingAs($this->viewer())
            ->test(ListIncidents::class)
            ->assertSee('Super QK9 family head')
            ->assertDontSee('Sub ZX7 hidden child')
            ->call('toggleExpandedSubIncidents', $super->id)
            ->assertSee('Sub ZX7 hidden child');
    }

    public function test_sub_rows_carry_a_row_class_for_family_styling(): void
    {
        [$super, $sub] = $this->seedFamily();

        // The sub row must be MARKED so CSS can indent it under its super
        // (owner 2026-11-16: the family should read as one unit) — before
        // expansion no sub rows render, so the marker appears only after.
        Livewire::actingAs($this->viewer())
            ->test(ListIncidents::class)
            ->assertDontSee('sub-incident-row')
            ->call('toggleExpandedSubIncidents', $super->id)
            ->assertSee('sub-incident-row');
    }

    public function test_search_surfaces_sub_rows(): void
    {
        [, $sub] = $this->seedFamily();

        Livewire::actingAs($this->viewer())
            ->test(ListIncidents::class)
            ->assertDontSee('Sub ZX7 hidden child')
            ->set('tableSearch', 'Sub ZX7 hidden child')
            ->assertSee('Sub ZX7 hidden child');
    }

    public function test_quick_period_default_keeps_subs_hidden_but_a_real_filter_surfaces_them(): void
    {
        [, $sub] = $this->seedFamily();

        // The Quick Period filter's own default ('year') must NOT count as an
        // active filter — subs stay hidden.
        Livewire::actingAs($this->viewer())
            ->test(ListIncidents::class)
            ->set('tableFilters', ['quick_period' => ['value' => 'year']])
            ->assertDontSee('Sub ZX7 hidden child');

        // A genuinely user-chosen filter surfaces them as normal rows.
        Livewire::actingAs($this->viewer())
            ->test(ListIncidents::class)
            ->set('tableFilters', ['quick_period' => ['value' => 'week']])
            ->assertSee('Sub ZX7 hidden child');
    }

    public function test_tab_and_heat_matrix_drill_in_surface_sub_rows(): void
    {
        [, $sub] = $this->seedFamily();

        Livewire::actingAs($this->viewer())
            ->test(ListIncidents::class)
            ->set('activeTab', 'On Going')
            ->assertSee('Sub ZX7 hidden child');

        // The heat matrix's ?ids= drill-in must render a SUB's own row too —
        // without surfacing, the default-view exclusion would blank it.
        Livewire::withQueryParams(['ids' => (string) $sub->id])
            ->actingAs($this->viewer())
            ->test(ListIncidents::class)
            ->assertSee('Sub ZX7 hidden child');
    }

    public function test_expanded_family_orders_children_under_the_super_under_default_sort(): void
    {
        $super = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'incident_date' => now()->startOfDay()->addHours(10)->subDays(10),
        ]);

        $sub = Incident::factory()->subOf($super)->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'incident_date' => now()->startOfDay()->addHours(10),
        ]);

        // Newer than the whole family — a plain date sort would put it first.
        $unrelated = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'incident_date' => now()->startOfDay()->addHours(10)->subDays(1),
        ]);

        $component = Livewire::actingAs($this->viewer())
            ->test(ListIncidents::class)
            ->call('toggleExpandedSubIncidents', $super->id);

        $orderedIds = $component->instance()
            ->getFilteredSortedTableQuery()
            ->pluck('id')
            ->all();

        $this->assertSame([$unrelated->id, $super->id, $sub->id], $orderedIds);
    }

    public function test_pagination_scope_is_free_of_hidden_subs(): void
    {
        [$super] = $this->seedFamily();
        Incident::factory()->subOf($super)->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
        ]);

        $component = Livewire::actingAs($this->viewer())->test(ListIncidents::class);

        $this->assertSame(1, $component->instance()->getFilteredTableQuery()->count());
    }

    public function test_unlink_requires_manage_permission_and_clears_the_link(): void
    {
        [$super, $sub] = $this->seedFamily();

        Livewire::actingAs($this->viewer())
            ->test(ListIncidents::class)
            ->call('unlinkSubIncident', $sub->id)
            ->assertForbidden();
        $this->assertNotNull($sub->fresh()->parent_incident_id);

        Permission::firstOrCreate(['name' => 'manage incidents']);
        $manager = User::factory()->create();
        $manager->givePermissionTo(['view incidents', 'manage incidents']);

        Livewire::actingAs($manager)
            ->test(ListIncidents::class)
            ->call('unlinkSubIncident', $sub->id);

        $this->assertNull($sub->fresh()->parent_incident_id);
    }
}
