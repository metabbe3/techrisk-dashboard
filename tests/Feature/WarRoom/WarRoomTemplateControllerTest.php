<?php

namespace Tests\Feature\WarRoom;

use App\Models\User;
use App\Models\WarRoomTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WarRoomTemplateControllerTest extends TestCase
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

    public function test_index_returns_user_templates(): void
    {
        WarRoomTemplate::create([
            'user_id' => $this->user->id,
            'name' => 'My Template',
            'selected_agents' => ['sre', 'tech_risk'],
            'max_rounds' => 2,
        ]);

        $otherUser = User::factory()->create();
        $otherUser->givePermissionTo('access war room');
        WarRoomTemplate::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Template',
            'selected_agents' => ['dba'],
            'max_rounds' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/templates');

        $response->assertStatus(200)
            ->assertJsonStructure(['templates']);

        $templates = $response->json('templates');
        $this->assertCount(1, $templates);
        $this->assertEquals('My Template', $templates[0]['name']);
    }

    public function test_store_creates_template(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/admin/war-room/templates', [
                'name' => 'Test Template',
                'selected_agents' => ['sre', 'tech_risk', 'dba'],
                'max_rounds' => 3,
                'enable_web_search' => true,
                'deep_analysis' => false,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('template.name', 'Test Template');

        $this->assertDatabaseHas('war_room_templates', [
            'user_id' => $this->user->id,
            'name' => 'Test Template',
            'max_rounds' => 3,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/admin/war-room/templates', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'selected_agents']);
    }

    public function test_store_validates_selected_agents_is_array(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/admin/war-room/templates', [
                'name' => 'Bad Template',
                'selected_agents' => 'not-array',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['selected_agents']);
    }

    public function test_update_modifies_own_template(): void
    {
        $template = WarRoomTemplate::create([
            'user_id' => $this->user->id,
            'name' => 'Original',
            'selected_agents' => ['sre'],
            'max_rounds' => 2,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/admin/war-room/templates/{$template->id}", [
                'name' => 'Updated',
                'max_rounds' => 3,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('template.name', 'Updated');

        $this->assertDatabaseHas('war_room_templates', [
            'id' => $template->id,
            'name' => 'Updated',
            'max_rounds' => 3,
        ]);
    }

    public function test_update_rejects_other_users_template(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->givePermissionTo('access war room');

        $template = WarRoomTemplate::create([
            'user_id' => $otherUser->id,
            'name' => 'Other',
            'selected_agents' => ['sre'],
            'max_rounds' => 2,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/admin/war-room/templates/{$template->id}", [
                'name' => 'Hijacked',
            ]);

        $response->assertStatus(404);
    }

    public function test_destroy_deletes_own_template(): void
    {
        $template = WarRoomTemplate::create([
            'user_id' => $this->user->id,
            'name' => 'Deletable',
            'selected_agents' => ['sre'],
            'max_rounds' => 2,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/admin/war-room/templates/{$template->id}");

        $response->assertStatus(200)
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('war_room_templates', [
            'id' => $template->id,
        ]);
    }

    public function test_destroy_rejects_other_users_template(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->givePermissionTo('access war room');

        $template = WarRoomTemplate::create([
            'user_id' => $otherUser->id,
            'name' => 'Protected',
            'selected_agents' => ['sre'],
            'max_rounds' => 2,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/admin/war-room/templates/{$template->id}");

        $response->assertStatus(404);
    }

    public function test_all_endpoints_require_authentication(): void
    {
        $this->getJson('/admin/war-room/templates')->assertStatus(401);
        $this->postJson('/admin/war-room/templates', [])->assertStatus(401);

        $fakeId = '00000000-0000-0000-0000-000000000000';
        $this->putJson("/admin/war-room/templates/{$fakeId}", [])->assertStatus(401);
        $this->deleteJson("/admin/war-room/templates/{$fakeId}")->assertStatus(401);
    }
}
