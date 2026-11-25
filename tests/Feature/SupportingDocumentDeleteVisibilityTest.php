<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\IncidentResource\Pages\ViewIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\InvestigationDocumentsRelationManager;
use App\Models\Incident;
use App\Models\InvestigationDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Supporting Documents on the ViewIncident page: Filament renders relation
 * managers READ-ONLY on ViewRecord pages by default, which hid create/edit/
 * delete there. The manager now opts out of read-only; the
 * InvestigationDocumentPolicy (manage incidents) is the gate that keeps
 * team-viewers and view-only accounts read-only — not the page type.
 * The row is simplified: Download + Edit + Delete as buttons, the secondary
 * AI/markdown actions behind one kebab ActionGroup.
 */
class SupportingDocumentDeleteVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['manage incidents', 'view incidents'] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }

    private function fixture(): array
    {
        config(['app.debug' => false]);
        $incident = Incident::factory()->create(['classification' => 'Incident']);
        $doc = InvestigationDocument::factory()->create(['incident_id' => $incident->id]);

        return [$incident, $doc];
    }

    private function renderTable(User $user, Incident $incident)
    {
        return Livewire::actingAs($user)->test(InvestigationDocumentsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => ViewIncident::class,
        ]);
    }

    public function test_manager_sees_delete_edit_upload_on_view_page(): void
    {
        [$incident, $doc] = $this->fixture();
        $manager = User::factory()->create()->givePermissionTo('manage incidents');

        $this->renderTable($manager, $incident)
            ->assertTableActionVisible('delete', $doc)
            ->assertTableActionVisible('edit', $doc)
            ->assertTableActionVisible('download', $doc);
    }

    public function test_view_only_user_cannot_delete_on_view_page(): void
    {
        [$incident, $doc] = $this->fixture();
        $viewer = User::factory()->create()->givePermissionTo('view incidents');

        $this->renderTable($viewer, $incident)
            ->assertTableActionHidden('delete', $doc)
            ->assertTableActionHidden('edit', $doc);
    }

    public function test_secondary_actions_are_grouped_into_one_kebab(): void
    {
        [$incident, $doc] = $this->fixture();
        $manager = User::factory()->create()->givePermissionTo('manage incidents');

        $component = $this->renderTable($manager, $incident);

        // Secondary actions still resolve from inside the group (flat registry).
        $component->assertTableActionVisible('reconvert', $doc);

        $topLevel = collect($component->instance()->getTable()->getActions())
            ->filter(fn ($action) => ! $action instanceof \Filament\Tables\Actions\ActionGroup)
            ->map(fn ($action) => $action->getName())
            ->values()->all();

        $this->assertSame(['download', 'edit', 'delete'], $topLevel);
    }
}
