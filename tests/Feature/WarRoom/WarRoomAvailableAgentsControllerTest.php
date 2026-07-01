<?php

namespace Tests\Feature\WarRoom;

use App\Models\User;
use App\Models\WarRoomAgentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WarRoomAvailableAgentsControllerTest extends TestCase
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

    public function test_agents_returns_active_agents(): void
    {
        WarRoomAgentConfig::factory()->create([
            'is_active' => true,
            'role_key' => 'sre',
            'display_name' => 'SRE Engineer',
            'description' => 'Site reliability analysis',
            'skills' => ['Monitoring', 'Incident Response'],
            'icon' => 'heroicon-o-server',
            'color' => 'blue',
            'enable_web_search' => true,
        ]);

        WarRoomAgentConfig::factory()->create([
            'is_active' => true,
            'role_key' => 'tech_risk',
            'display_name' => 'Tech Risk Analyst',
            'description' => 'Risk assessment',
            'skills' => [['skill' => 'Risk Analysis'], ['skill' => 'Compliance']],
            'icon' => 'heroicon-o-shield-check',
            'color' => 'green',
            'enable_web_search' => false,
        ]);

        // Moderator should be excluded from results
        WarRoomAgentConfig::factory()->create([
            'is_active' => true,
            'role_key' => 'moderator',
            'display_name' => 'Moderator',
            'description' => 'Discussion moderator',
            'skills' => ['Facilitation'],
            'icon' => 'heroicon-o-user',
            'color' => 'amber',
            'enable_web_search' => false,
        ]);

        // Inactive agent should be excluded
        WarRoomAgentConfig::factory()->inactive()->create([
            'role_key' => 'inactive_agent',
            'display_name' => 'Inactive Agent',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/agents');

        $response->assertStatus(200);

        $agents = $response->json('data');

        // Moderator should be excluded
        $roleKeys = collect($agents)->pluck('role_key')->toArray();
        $this->assertNotContains('moderator', $roleKeys);
        $this->assertNotContains('inactive_agent', $roleKeys);
        $this->assertContains('sre', $roleKeys);
        $this->assertContains('tech_risk', $roleKeys);

        // Verify the structure of the sre agent
        $sreAgent = collect($agents)->first(fn ($a) => $a['role_key'] === 'sre');
        $this->assertEquals('SRE Engineer', $sreAgent['display_name']);
        $this->assertEquals('Site reliability analysis', $sreAgent['description']);
        $this->assertEquals(['Monitoring', 'Incident Response'], $sreAgent['skills']);
        $this->assertEquals('heroicon-o-server', $sreAgent['icon']);
        $this->assertEquals('blue', $sreAgent['color']);
        $this->assertTrue($sreAgent['enable_web_search']);
    }

    public function test_agents_handles_skill_array_format(): void
    {
        WarRoomAgentConfig::query()->delete();

        WarRoomAgentConfig::factory()->create([
            'is_active' => true,
            'role_key' => 'dba',
            'skills' => [['skill' => 'Query Optimization'], ['skill' => 'Backup']],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/agents');

        $response->assertStatus(200);

        $agent = collect($response->json('data'))->first(fn ($a) => $a['role_key'] === 'dba');
        $this->assertEquals(['Query Optimization', 'Backup'], $agent['skills']);
    }

    public function test_agents_filters_empty_skills(): void
    {
        WarRoomAgentConfig::query()->delete();

        WarRoomAgentConfig::factory()->create([
            'is_active' => true,
            'role_key' => 'analyst',
            'skills' => ['Valid Skill', '', null],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/agents');

        $response->assertStatus(200);

        $agent = collect($response->json('data'))->first(fn ($a) => $a['role_key'] === 'analyst');
        // Empty and null skills should be filtered out
        $this->assertEquals(['Valid Skill'], $agent['skills']);
    }

    public function test_agents_requires_authentication(): void
    {
        $response = $this->getJson('/admin/war-room/agents');

        $response->assertStatus(401);
    }

    public function test_agents_returns_empty_when_no_active_agents(): void
    {
        WarRoomAgentConfig::query()->delete();

        WarRoomAgentConfig::factory()->create(['is_active' => false, 'role_key' => 'inactive']);

        $response = $this->actingAs($this->user)
            ->getJson('/admin/war-room/agents');

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }
}
