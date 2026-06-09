<?php

namespace Tests\Feature\WarRoom;

use App\Models\User;
use App\Models\WarRoomMessage;
use App\Models\WarRoomSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WarRoomDeleteControllerTest extends TestCase
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

    public function test_delete_session_success(): void
    {
        $session = WarRoomSession::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // Create associated messages that should also be deleted
        WarRoomMessage::factory()->count(3)->create([
            'session_id' => $session->id,
        ]);

        $this->assertDatabaseHas('war_room_sessions', ['id' => $session->id]);
        $this->assertEquals(3, WarRoomMessage::where('session_id', $session->id)->count());

        $response = $this->actingAs($this->user)
            ->deleteJson("/admin/war-room/sessions/{$session->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('war_room_sessions', ['id' => $session->id]);
        $this->assertEquals(0, WarRoomMessage::where('session_id', $session->id)->count());
    }

    public function test_delete_only_own_sessions(): void
    {
        $otherUser = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access war room']);
        $otherUser->givePermissionTo('access war room');

        $session = WarRoomSession::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/admin/war-room/sessions/{$session->id}");

        $response->assertStatus(403);

        // Verify session still exists
        $this->assertDatabaseHas('war_room_sessions', ['id' => $session->id]);
    }

    public function test_delete_requires_authentication(): void
    {
        $session = WarRoomSession::factory()->create(['user_id' => $this->user->id]);

        $response = $this->deleteJson("/admin/war-room/sessions/{$session->id}");

        $response->assertStatus(401);
    }

    public function test_delete_returns_404_for_nonexistent_session(): void
    {
        $response = $this->actingAs($this->user)
            ->deleteJson('/admin/war-room/sessions/nonexistent-id');

        $response->assertStatus(404);
    }
}
