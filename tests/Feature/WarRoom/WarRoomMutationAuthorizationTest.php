<?php

namespace Tests\Feature\WarRoom;

use App\Models\User;
use App\Models\WarRoomSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Reading a session stays open to anyone with `access war room` (so teammates
 * reuse one retrospective and save tokens), but mutating actions are restricted
 * to the creator.
 */
class WarRoomMutationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'access war room']);
        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo('access war room');
    }

    private function someoneElsesSession(): WarRoomSession
    {
        $owner = User::factory()->create();
        $owner->givePermissionTo('access war room');

        return WarRoomSession::factory()->completed()->create([
            'user_id' => $owner->id,
        ]);
    }

    public function test_other_permitted_user_can_view_a_session_open_view(): void
    {
        $session = $this->someoneElsesSession();

        $this->actingAs($this->viewer)
            ->getJson("/admin/war-room/sessions/{$session->id}")
            ->assertOk();
    }

    public function test_other_permitted_user_cannot_retry(): void
    {
        $session = $this->someoneElsesSession();

        $this->actingAs($this->viewer)
            ->postJson("/admin/war-room/sessions/{$session->id}/retry")
            ->assertStatus(403);
    }

    public function test_other_permitted_user_cannot_reanalyze(): void
    {
        $session = $this->someoneElsesSession();

        $this->actingAs($this->viewer)
            ->postJson("/admin/war-room/sessions/{$session->id}/reanalyze")
            ->assertStatus(403);
    }

    public function test_other_permitted_user_cannot_draft_actions(): void
    {
        $session = $this->someoneElsesSession();

        $this->actingAs($this->viewer)
            ->postJson("/admin/war-room/sessions/{$session->id}/draft-actions")
            ->assertStatus(403);
    }

    public function test_other_permitted_user_cannot_delete(): void
    {
        $session = $this->someoneElsesSession();

        $this->actingAs($this->viewer)
            ->deleteJson("/admin/war-room/sessions/{$session->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('war_room_sessions', ['id' => $session->id]);
    }

    public function test_owner_can_retry_their_own_session(): void
    {
        $session = WarRoomSession::factory()->completed()->create([
            'user_id' => $this->viewer->id,
        ]);

        // Completed session, no failed agents -> 400 from controller logic
        // (proves the guard passed for the owner; a non-owner would be 403).
        $this->actingAs($this->viewer)
            ->postJson("/admin/war-room/sessions/{$session->id}/retry")
            ->assertStatus(400);
    }
}
