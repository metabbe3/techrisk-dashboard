<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentClassification;
use App\Enums\Severity;
use App\Livewire\IncidentKanbanBoard;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PROJ-022 Task 9: kanban board cards surface the hierarchy — a sub card
 * shows a "Sub of {super no}" chip, a super card shows its sub count.
 *
 * The full board component cannot render under the SQLite test DB (the
 * FIELD()-based severity order — UserFacingCrashersTest), so the chips live
 * in a partial blade the test renders with real records, and the card query
 * contract is checked on the builder, not the HTML.
 */
class SubIncidentBoardChipsTest extends TestCase
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

    private function cardQuery(): \Illuminate\Database\Eloquent\Builder
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo('view incidents');
        $this->actingAs($user);

        $board = new IncidentKanbanBoard;
        $method = new ReflectionMethod($board, 'buildQuery');

        return $method->invoke($board);
    }

    public function test_card_query_carries_the_hierarchy_data(): void
    {
        $query = $this->cardQuery();
        $sql = $query->toSql();

        $this->assertStringContainsString('parent_incident_id', $sql);
        $this->assertStringContainsString('sub_incidents_count', $sql);
        $this->assertArrayHasKey('superIncident', $query->getEagerLoads());
    }

    public function test_sub_card_shows_a_sub_of_chip_linking_the_super(): void
    {
        $super = $this->incident();
        $sub = Incident::factory()->subOf($super)->createQuietly([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P2->value,
        ]);

        $html = view('livewire.incident-kanban-sub-chips', ['incident' => $sub->fresh()])->render();

        $this->assertStringContainsString('Sub of', $html);
        $this->assertStringContainsString((string) $super->no, $html);
        $this->assertStringContainsString('viewIncident('.$super->id.')', $html);
    }

    public function test_super_card_shows_its_sub_count(): void
    {
        $super = $this->incident();
        Incident::factory()->subOf($super)->createQuietly([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
        ]);
        $super = $super->fresh();

        $superCard = view('livewire.incident-kanban-sub-chips', ['incident' => $super->loadCount('subIncidents')])->render();
        $this->assertStringContainsString('1 sub', $superCard);
    }

    public function test_standalone_card_shows_neither_chip(): void
    {
        $standalone = $this->incident()->fresh();

        $html = view('livewire.incident-kanban-sub-chips', ['incident' => $standalone])->render();

        $this->assertStringNotContainsString('Sub of', $html);
        $this->assertStringNotContainsString('sub</span>', $html);
    }
}
