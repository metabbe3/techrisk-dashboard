<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class IncidentApiTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        // Ensure the 'access api' permission exists before assigning it
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');
        Sanctum::actingAs($user);
    }

    public function test_can_get_all_incidents()
    {
        Incident::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/incidents');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
    }

    public function test_can_create_incident()
    {
        $incidentType = IncidentType::factory()->create();

        $data = [
            'no' => now()->format('Y') . '_IN_P1_' . $this->faker->unique()->randomNumber(3, true),
            'title' => $this->faker->sentence,
            'summary' => $this->faker->paragraph,
            'severity' => 'p1',
            'incident_type' => 'Tech', // Use an enum value
            'incident_date' => now()->toDateString(),
            'entry_date_tech_risk' => now()->toDateString(),
        ];

        $response = $this->postJson('/api/v1/incidents', $data);

        $response->assertStatus(201);
        $this->assertDatabaseHas('incidents', ['title' => $data['title']]);
    }

    public function test_can_get_single_incident()
    {
        $incident = Incident::factory()->create();

        $response = $this->getJson('/api/v1/incidents/' . $incident->id);

        $response->assertStatus(200);
        $response->assertJson(['data' => ['incident_name' => $incident->title]]);
    }

    public function test_can_update_incident()
    {
        $incident = Incident::factory()->create();

        $data = [
            'title' => 'Updated Title',
        ];

        $response = $this->putJson('/api/v1/incidents/' . $incident->id, $data);

        $response->assertStatus(200);
        $this->assertDatabaseHas('incidents', ['id' => $incident->id, 'title' => 'Updated Title']);
    }

    public function test_can_delete_incident()
    {
        $incident = Incident::factory()->create();

        $response = $this->deleteJson('/api/v1/incidents/' . $incident->id);

        $response->assertStatus(204);
        $this->assertDatabaseMissing('incidents', ['id' => $incident->id]);
    }
}