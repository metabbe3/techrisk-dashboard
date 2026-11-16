<?php

namespace Tests\Feature\Api;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

// The DingTalk agent's search_incidents projects its rows from this endpoint;
// the 2026-11-16 key drift (resource renamed no/title → incident_no/
// incident_title without the consumer noticing) stripped the incident number
// from every search row and blocked the external-RCA flow. These fields are
// the agent's identity set — remove one and the agent goes blind again.
class IncidentListApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_rows_carry_the_agent_identity_fields(): void
    {
        Permission::firstOrCreate(['name' => 'access api']);
        $user = User::factory()->create();
        $user->givePermissionTo('access api');
        $token = $user->createToken('test', ['*']);

        $incident = Incident::factory()->create()->refresh(); // factory leaves DB-default columns (incident_status) stale in memory

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/incidents');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $incident->id)
            ->assertJsonPath('data.0.incident_no', $incident->no)
            ->assertJsonPath('data.0.incident_title', $incident->title)
            ->assertJsonPath('data.0.severity', $incident->severity)
            ->assertJsonPath('data.0.incident_date', $incident->incident_date->format('Y-m-d'))
            ->assertJsonPath('data.0.incident_status', $incident->incident_status);

        $row = $response->json('data.0');
        $this->assertArrayHasKey('summary', $row);
        $this->assertArrayHasKey('created_at', $row);
        $this->assertArrayHasKey('updated_at', $row);
        // Y-m-d, not the ISO datetime the detail resource emits
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['incident_date']);
    }
}
