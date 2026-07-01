<?php

namespace Tests\Feature\WarRoom;

use App\Models\User;
use App\Models\WarRoomSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WarRoomListControllerTest extends TestCase
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

    public function test_list_returns_sessions(): void
    {
        WarRoomSession::factory()->count(3)->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/sessions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        '*' => ['id', 'title', 'status', 'current_round', 'max_rounds'],
                    ],
                ],
            ]);

        // The list controller does NOT scope to user -- it returns all sessions.
        // Verify the count matches what we created.
        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_list_returns_paginated_results(): void
    {
        WarRoomSession::factory()->count(25)->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/sessions');

        $response->assertStatus(200);

        // Default pagination is 20 per page
        $this->assertCount(20, $response->json('data.data'));
        $response->assertJsonStructure(['data' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function test_list_can_filter_by_incident_id(): void
    {
        $session1 = WarRoomSession::factory()->create([
            'user_id' => $this->user->id,
        ]);
        $session2 = WarRoomSession::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/sessions?incident_id='.$session1->incident_id);

        $response->assertStatus(200);

        $data = $response->json('data.data');
        foreach ($data as $item) {
            $this->assertEquals($session1->incident_id, $item['incident_id']);
        }
    }

    public function test_list_orders_by_updated_at_desc(): void
    {
        $oldest = WarRoomSession::factory()->create([
            'user_id' => $this->user->id,
            'updated_at' => now()->subDays(2),
        ]);
        $newest = WarRoomSession::factory()->create([
            'user_id' => $this->user->id,
            'updated_at' => now(),
        ]);
        $middle = WarRoomSession::factory()->create([
            'user_id' => $this->user->id,
            'updated_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/sessions');

        $response->assertStatus(200);

        $ids = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertEquals($newest->id, $ids[0]);
    }

    public function test_list_requires_authentication(): void
    {
        $response = $this->getJson('/admin/war-room/sessions');

        $response->assertStatus(401);
    }
}
