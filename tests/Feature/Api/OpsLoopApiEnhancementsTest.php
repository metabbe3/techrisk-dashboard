<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\IncidentClassification;
use App\Models\ActionImprovement;
use App\Models\ActionImprovementEvidence;
use App\Models\Incident;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OpsLoopApiEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(array $endpoints): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Permission::firstOrCreate(['name' => 'access api']);
        $admin->givePermissionTo('access api');

        $token = $admin->createToken('test', ['*']);
        PersonalAccessToken::find($token->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode($endpoints)])
            ->save();

        return [$admin, $token->plainTextToken];
    }

    public function test_action_improvements_search_supports_pending_approval_and_exposes_proof_flags(): void
    {
        [$admin, $token] = $this->adminToken(['action-improvements']);

        $incident = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'incident_date' => now(),
        ]);

        $withProof = $incident->actionImprovements()->create([
            'title' => 'Has pending proof',
            'detail' => 'Detail',
            'pic_email' => ['worker@dana.id'],
            'status' => ActionImprovement::STATUS_PENDING,
        ]);
        ActionImprovementEvidence::create([
            'action_improvement_id' => $withProof->id,
            'link' => 'https://example.com/evidence',
            'submitted_by' => $admin->id,
            'approved_at' => null,
        ]);

        $incident->actionImprovements()->create([
            'title' => 'No proof yet',
            'detail' => 'Detail',
            'pic_email' => ['worker@dana.id'],
            'status' => ActionImprovement::STATUS_ON_PROGRESS,
        ]);

        // Default (no status param) returns BOTH pending and on_progress
        $this->withToken($token)
            ->getJson('/api/v1/action-improvements')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // pending_approval=1 returns only the row with unapproved worker proof
        $this->withToken($token)
            ->getJson('/api/v1/action-improvements?pending_approval=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $withProof->id)
            ->assertJsonPath('data.0.has_pending_proof', true)
            ->assertJsonPath('data.0.pending_proof_count', 1);
    }

    public function test_incidents_api_exposes_sub_incident_hierarchy_fields(): void
    {
        [, $token] = $this->adminToken(['incidents', 'incidents-by-no']);

        $super = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'incident_date' => now(),
        ]);
        $sub = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'parent_incident_id' => $super->id,
            'incident_date' => now(),
        ]);

        // List endpoint includes sub_incidents_count and parent_incident_no
        $list = $this->withToken($token)
            ->getJson('/api/v1/incidents')
            ->assertOk()
            ->json('data');

        $superRow = collect($list)->firstWhere('id', $super->id);
        $subRow = collect($list)->firstWhere('id', $sub->id);

        $this->assertSame(1, $superRow['sub_incidents_count']);
        $this->assertNull($superRow['parent_incident_no']);
        $this->assertSame($super->id, $subRow['parent_incident_id']);
        $this->assertSame($super->no, $subRow['parent_incident_no']);

        // Show endpoint includes sub_incidents array on super and parent_incident_no on sub
        $this->withToken($token)
            ->getJson('/api/v1/incidents/'.$super->id)
            ->assertOk()
            ->assertJsonPath('data.sub_incidents.0.incident_no', $sub->no);

        $this->withToken($token)
            ->getJson('/api/v1/incidents-by-no/'.$sub->no)
            ->assertOk()
            ->assertJsonPath('data.parent_incident_no', $super->no);
    }
}
