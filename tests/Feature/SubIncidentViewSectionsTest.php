<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentClassification;
use App\Enums\Severity;
use App\Filament\Resources\IncidentResource\Pages\ViewIncident;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PROJ-022 Task 8: the incident view page renders the hierarchy — a super
 * gets a "Sub-incidents (N)" section, a sub gets a "Sub of" section, a
 * standalone shows neither.
 */
class SubIncidentViewSectionsTest extends TestCase
{
    use RefreshDatabase;

    private function incident(array $attrs = []): Incident
    {
        return Incident::factory()->createQuietly(array_merge([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'incident_date' => now()->startOfDay()->addHours(10),
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

    public function test_super_page_shows_the_sub_incidents_section(): void
    {
        $super = $this->incident();
        $sub1 = Incident::factory()->subOf($super)->createQuietly([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P2->value,
        ]);
        $sub2 = Incident::factory()->subOf($super)->createQuietly([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P3->value,
        ]);

        Livewire::actingAs($this->manager())
            ->test(ViewIncident::class, ['record' => (string) $super->id])
            ->assertSee('Sub-incidents (2)')
            ->assertSee($sub1->no)
            ->assertSee($sub1->title)
            ->assertSee($sub2->no)
            ->assertDontSee('Sub of');
    }

    public function test_sub_page_shows_the_super_as_sub_of(): void
    {
        $super = $this->incident();
        $sub = Incident::factory()->subOf($super)->createQuietly([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
        ]);

        Livewire::actingAs($this->manager())
            ->test(ViewIncident::class, ['record' => (string) $sub->id])
            ->assertSee('Sub of')
            ->assertSee($super->no)
            ->assertDontSee('Sub-incidents (');
    }

    public function test_standalone_page_shows_neither_section(): void
    {
        $standalone = $this->incident();

        Livewire::actingAs($this->manager())
            ->test(ViewIncident::class, ['record' => (string) $standalone->id])
            ->assertDontSee('Sub of')
            ->assertDontSee('Sub-incidents (');
    }

    public function test_unlink_from_the_view_page_keeps_both_records(): void
    {
        $super = $this->incident();
        $sub = Incident::factory()->subOf($super)->createQuietly([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
        ]);

        Livewire::actingAs($this->manager())
            ->test(ViewIncident::class, ['record' => (string) $super->id])
            ->call('unlinkSubIncident', $sub->id);

        $this->assertNull($sub->fresh()->parent_incident_id);
        $this->assertNotNull(Incident::find($super->id));
        $this->assertNotNull(Incident::find($sub->id));
    }
}
