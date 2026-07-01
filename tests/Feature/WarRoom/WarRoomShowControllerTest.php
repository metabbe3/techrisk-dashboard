<?php

namespace Tests\Feature\WarRoom;

use App\Models\User;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WarRoomShowControllerTest extends TestCase
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

    public function test_show_returns_session_data(): void
    {
        $session = WarRoomSession::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'completed',
            'title' => 'Test Session',
        ]);

        $expectedData = [
            'id' => $session->id,
            'title' => 'Test Session',
            'status' => 'completed',
            'current_round' => 0,
            'max_rounds' => 2,
        ];

        $mockService = Mockery::mock(WarRoomService::class);
        $mockService->shouldReceive('getSessionData')
            ->once()
            ->withArgs(fn ($arg) => $arg->is($session))
            ->andReturn($expectedData);

        $this->app->instance(WarRoomService::class, $mockService);

        $response = $this->actingAs($this->user)
            ->getJson("/admin/war-room/sessions/{$session->id}");

        $response->assertStatus(200)
            ->assertJson(['data' => $expectedData]);
    }

    public function test_show_allows_viewing_other_users_sessions(): void
    {
        $otherUser = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access war room']);
        $otherUser->givePermissionTo('access war room');

        $session = WarRoomSession::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'completed',
        ]);

        $expectedData = [
            'id' => $session->id,
            'title' => $session->title,
            'status' => 'completed',
        ];

        $mockService = Mockery::mock(WarRoomService::class);
        $mockService->shouldReceive('getSessionData')
            ->once()
            ->andReturn($expectedData);

        $this->app->instance(WarRoomService::class, $mockService);

        $response = $this->actingAs($this->user)
            ->getJson("/admin/war-room/sessions/{$session->id}");

        $response->assertStatus(200);
    }

    public function test_show_requires_authentication(): void
    {
        $session = WarRoomSession::factory()->create(['user_id' => $this->user->id]);

        $response = $this->getJson("/admin/war-room/sessions/{$session->id}");

        $response->assertStatus(401);
    }

    public function test_show_returns_404_for_nonexistent_session(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/sessions/nonexistent-id');

        $response->assertStatus(404);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
