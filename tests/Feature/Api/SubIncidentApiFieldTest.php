<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\IncidentClassification;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PROJ-022 Task 11: the API exposes parent_incident_id on incidents —
 * null on supers/standalones, the super's id on subs. No new endpoints.
 */
class SubIncidentApiFieldTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');
        $this->token = $user->createToken('test-token')->plainTextToken;
    }

    public function test_show_includes_parent_incident_id(): void
    {
        $super = Incident::factory()->createQuietly([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
        ]);
        $sub = Incident::factory()->subOf($super)->createQuietly([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P2->value,
        ]);

        $subBody = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/incidents-by-no/'.urlencode($sub->no))
            ->assertStatus(200)
            ->json('data');
        $this->assertSame($super->id, $subBody['parent_incident_id']);

        $superBody = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/incidents-by-no/'.urlencode($super->no))
            ->assertStatus(200)
            ->json('data');
        $this->assertNull($superBody['parent_incident_id']);
    }

    public function test_index_rows_include_parent_incident_id(): void
    {
        $super = Incident::factory()->createQuietly([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
        ]);
        $sub = Incident::factory()->subOf($super)->createQuietly([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P2->value,
        ]);

        $rows = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/incidents')
            ->assertStatus(200)
            ->json('data');

        $byNo = collect($rows)->keyBy('incident_no');
        $this->assertSame($super->id, $byNo[$sub->no]['parent_incident_id']);
        $this->assertNull($byNo[$super->no]['parent_incident_id']);
    }
}
