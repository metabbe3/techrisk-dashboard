<?php

namespace Tests\Feature\WarRoom;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WarRoomIncidentSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access war room']);
        $this->user->givePermissionTo('access war room');
    }

    public function test_search_returns_results(): void
    {
        Event::fake();

        $matchingIncident = Incident::factory()->create([
            'title' => 'Production Database Outage',
            'no' => '20260501_IN_0001',
            'summary' => 'Critical database failure',
        ]);

        $nonMatchingIncident = Incident::factory()->create([
            'title' => 'Unrelated Network Issue',
            'no' => '20260501_IN_0002',
            'summary' => 'Switch configuration problem',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/incident-search?q=Database');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['incidents']]);

        $incidents = $response->json('data.incidents');

        // Should find the matching incident by title
        $found = collect($incidents)->contains(fn ($i) => $i['id'] === $matchingIncident->id);
        $this->assertTrue($found, 'Expected to find the matching incident by title');

        // Should not include the non-matching incident
        $notFound = collect($incidents)->contains(fn ($i) => $i['id'] === $nonMatchingIncident->id);
        $this->assertFalse($notFound, 'Expected NOT to find the non-matching incident');
    }

    public function test_search_returns_empty_for_short_query(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/incident-search?q=a');

        $response->assertStatus(200)
            ->assertJsonPath('data.incidents', []);
    }

    public function test_search_returns_empty_when_q_is_missing(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/incident-search');

        $response->assertStatus(200)
            ->assertJsonPath('data.incidents', []);
    }

    public function test_search_requires_authentication(): void
    {
        $response = $this->getJson('/admin/war-room/incident-search?q=test');

        $response->assertStatus(401);
    }

    public function test_search_limits_results_to_10(): void
    {
        Event::fake();

        // Create 15 incidents that all match the search term
        Incident::factory()->count(15)->create([
            'title' => 'Test Incident Search Match',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/incident-search?q=Search');

        $response->assertStatus(200);
        $incidents = $response->json('data.incidents');
        $this->assertCount(10, $incidents);
    }

    public function test_search_matches_incident_number(): void
    {
        Event::fake();
        $incident = Incident::factory()->create([
            'no' => '20260515_IN_0042',
            'title' => 'Completely unrelated title',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/incident-search?q=20260515');

        $response->assertStatus(200);

        $found = collect($response->json('data.incidents'))->contains(fn ($i) => $i['id'] === $incident->id);
        $this->assertTrue($found, 'Expected to find the incident by number');
    }

    public function test_search_matches_summary(): void
    {
        Event::fake();
        $incident = Incident::factory()->create([
            'title' => 'Boring Title',
            'summary' => 'This is a unique summary about payment gateway failures',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/incident-search?q=payment gateway');

        $response->assertStatus(200);

        $found = collect($response->json('data.incidents'))->contains(fn ($i) => $i['id'] === $incident->id);
        $this->assertTrue($found, 'Expected to find the incident by summary');
    }

    public function test_search_returns_correct_structure(): void
    {
        Event::fake();
        $pic = User::factory()->create();
        Incident::factory()->create([
            'title' => 'Searchable Incident',
            'pic_id' => $pic->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/incident-search?q=Searchable');

        $response->assertStatus(200);

        $incidents = $response->json('data.incidents');
        $this->assertNotEmpty($incidents);

        $first = $incidents[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('no', $first);
        $this->assertArrayHasKey('title', $first);
        $this->assertArrayHasKey('severity', $first);
        $this->assertArrayHasKey('status', $first);
        $this->assertArrayHasKey('date', $first);
        $this->assertArrayHasKey('pic', $first);
        $this->assertArrayHasKey('classification', $first);
    }
}
