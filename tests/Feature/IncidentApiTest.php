<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class IncidentApiTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');
        $this->token = $user->createToken('test-token')->plainTextToken;
    }

    public function test_can_get_all_incidents(): void
    {
        Incident::factory()->count(3)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/incidents');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'code',
                'status',
                'message',
                'data' => [
                    '*' => ['id', 'incident_no', 'incident_name'],
                ],
            ]);
    }

    public function test_can_get_single_incident(): void
    {
        $incident = Incident::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/incidents/'.$incident->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'code',
                'status',
                'message',
                'data' => [
                    'id',
                    'incident_no',
                    'incident_name',
                ],
            ])
            ->assertJsonPath('data.incident_name', $incident->title);
    }
}
