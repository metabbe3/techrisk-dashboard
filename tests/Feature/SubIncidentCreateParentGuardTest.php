<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Review I4 (PROJ-022): the create form's hidden parent_incident_id is fed
 * from a CRAFTABLE URL (?parent=<id>) — without validation, ?parent=<sub id>
 * chains a sub under a sub (depth violation), ?parent=<issue id> hangs an
 * incident family off an Issue, and ?parent=<garbage> 500s on the FK.
 */
class SubIncidentCreateParentGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'manage incidents']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['view incidents', 'manage incidents']);
    }

    private function submitWithParent(int|string $parentId, string $title = 'Guard probe incident'): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::withQueryParams(['parent' => (string) $parentId])
            ->actingAs($this->user)
            ->test(\App\Filament\Resources\IncidentResource\Pages\CreateIncident::class)
            ->fillForm([
                'title' => $title,
                'severity' => 'P2',
                'classification' => IncidentClassification::Incident->value,
                'incident_date' => now()->format('Y-m-d H:i:s'),
                'incident_type' => 'Tech',
                'incident_source' => 'Internal',
                'entry_date_tech_risk' => now()->format('Y-m-d H:i:s'),
            ])
            ->call('create');
    }

    public function test_rejects_a_sub_as_parent_one_level_deep(): void
    {
        $super = Incident::factory()->create(['classification' => IncidentClassification::Incident->value]);
        $sub = Incident::factory()->subOf($super)->create(['classification' => IncidentClassification::Incident->value]);

        $this->submitWithParent($sub->id)
            ->assertHasFormErrors(['parent_incident_id']);

        $this->assertDatabaseMissing('incidents', ['title' => 'Guard probe incident']);
    }

    public function test_rejects_an_issue_as_parent(): void
    {
        $issue = Incident::factory()->create(['classification' => IncidentClassification::Issue->value]);

        $this->submitWithParent($issue->id)
            ->assertHasFormErrors(['parent_incident_id']);

        $this->assertDatabaseMissing('incidents', ['title' => 'Guard probe incident']);
    }

    public function test_rejects_a_nonexistent_parent_instead_of_fk_500(): void
    {
        $this->submitWithParent(999999)
            ->assertHasFormErrors(['parent_incident_id']);

        $this->assertDatabaseMissing('incidents', ['title' => 'Guard probe incident']);
    }

    public function test_accepts_a_valid_super(): void
    {
        $super = Incident::factory()->create(['classification' => IncidentClassification::Incident->value]);

        $this->submitWithParent($super->id, 'Legit sub via URL')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('incidents', [
            'title' => 'Legit sub via URL',
            'parent_incident_id' => $super->id,
        ]);
    }
}
