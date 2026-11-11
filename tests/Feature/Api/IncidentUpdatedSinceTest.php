<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class IncidentUpdatedSinceTest extends TestCase
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

    /** @test */
    public function updated_since_filters_by_row_update_time(): void
    {
        $old = Incident::factory()->create(['severity' => 'P1', 'classification' => 'Incident', 'incident_date' => now()]);
        $old->forceFill(['updated_at' => '2026-11-10 10:00:00'])->saveQuietly();

        $new = Incident::factory()->create(['severity' => 'P1', 'classification' => 'Incident', 'incident_date' => now()]);
        $new->forceFill(['updated_at' => '2026-11-11 10:00:00'])->saveQuietly();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/incidents?'.http_build_query([
                'updated_since' => '2026-11-11T00:00:00Z',
                'limit' => 50,
            ]));

        $response->assertStatus(200);
        $nos = collect($response->json('data'))->pluck('incident_no')->all();

        $this->assertContains($new->no, $nos);
        $this->assertNotContains($old->no, $nos);
    }

    /** @test */
    public function updated_since_is_optional(): void
    {
        $a = Incident::factory()->create(['severity' => 'P1', 'classification' => 'Incident', 'incident_date' => now()]);
        $a->forceFill(['updated_at' => '2026-11-01 08:00:00'])->saveQuietly();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/incidents?limit=50');

        $response->assertStatus(200);
        $nos = collect($response->json('data'))->pluck('incident_no')->all();
        $this->assertContains($a->no, $nos);
    }

    /** @test */
    public function updated_since_must_be_a_date(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/incidents?'.http_build_query(['updated_since' => 'not-a-date']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['updated_since']);
    }
}
