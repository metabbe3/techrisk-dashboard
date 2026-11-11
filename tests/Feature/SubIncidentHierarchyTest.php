<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentClassification;
use App\Enums\Severity;
use App\Jobs\CalculateIncidentMetrics;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Sub-incident hierarchy core (PROJ-022): the parent link, the roll-up
 * scopes, orphaning on super delete, and the observer trigger that keeps
 * dashboard caches fresh after a link change (edit-safety rule 2).
 */
class SubIncidentHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private string $incidentClass;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin classification — the factory randomizes it.
        $this->incidentClass = IncidentClassification::Incident->value;
    }

    public function test_links_sub_incidents_to_a_super_and_reports_link_state(): void
    {
        $super = Incident::factory()->create(['classification' => $this->incidentClass]);
        $sub = Incident::factory()->subOf($super)->create(['classification' => $this->incidentClass]);
        $standalone = Incident::factory()->create(['classification' => $this->incidentClass]);

        $this->assertTrue($super->subIncidents->pluck('id')->contains($sub->id));
        $this->assertTrue($sub->superIncident->is($super));
        $this->assertTrue($super->hasIncidentLinks());
        $this->assertTrue($sub->hasIncidentLinks());
        $this->assertFalse($standalone->hasIncidentLinks());
    }

    public function test_excludes_sub_incidents_from_count_scopes_unless_flagged(): void
    {
        $super = Incident::factory()->create([
            'classification' => $this->incidentClass,
            'severity' => Severity::P1->value,
        ]);
        Incident::factory()->subOf($super)->create([
            'classification' => $this->incidentClass,
            'severity' => Severity::P1->value,
        ]);

        $this->assertSame(1, Incident::query()->countEligible()->count());
        $this->assertSame(1, Incident::query()->aiCounts()->count());
        $this->assertSame(2, Incident::query()->countEligible(withSubIncidents: true)->count());
        $this->assertSame(2, Incident::query()->aiCounts(withSubIncidents: true)->count());
        $this->assertSame(1, Incident::query()->withoutSubIncidents()->count());
    }

    public function test_orphans_subs_when_the_super_is_deleted(): void
    {
        $super = Incident::factory()->create();
        $sub = Incident::factory()->subOf($super)->create();

        $super->delete();

        $this->assertNull($sub->fresh()->parent_incident_id);
    }

    public function test_dispatches_metric_recalculation_when_the_link_changes(): void
    {
        Bus::fake([CalculateIncidentMetrics::class]);

        // createQuietly: seed without observer dispatches so the ONLY pushed
        // job can be the one the link change triggers (house precedent for
        // observer-isolated seeds).
        $super = Incident::factory()->createQuietly();
        $sub = Incident::factory()->createQuietly();

        $sub->update(['parent_incident_id' => $super->id]);

        // Exactly one dispatch — the link change (seeds were quiet).
        Bus::assertDispatchedTimes(CalculateIncidentMetrics::class, 1);
    }
}
