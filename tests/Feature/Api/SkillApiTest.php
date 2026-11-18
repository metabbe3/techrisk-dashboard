<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\PersonalAccessToken;
use App\Models\Skill;
use App\Models\User;
use App\Models\WarRoomAgentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /api/v1/skills — the DingTalk agent's window into the dashboard skill
 * library (Filament → Skills) and the War Room persona configs. Read-only,
 * on-demand: the index is a cheap catalog (no content), the show route loads
 * ONE body, capped at 48k chars server-side. Persona entries (role-*) expose
 * the system prompt + attached skill NAMES — never the bodies.
 */
class SkillApiTest extends TestCase
{
    use RefreshDatabase;

    private function tokenWith(array $endpoints): string
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');

        $token = $user->createToken('test', ['*']);
        PersonalAccessToken::find($token->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode($endpoints)])
            ->save();

        return $token->plainTextToken;
    }

    public function test_unauthenticated_request_gets_401(): void
    {
        $this->getJson('/api/v1/skills')->assertStatus(401);
    }

    public function test_token_without_skills_endpoint_gets_403(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['incidents']))
            ->getJson('/api/v1/skills')
            ->assertStatus(403)
            ->assertJsonPath('message', 'This token does not have permission to access this endpoint.');
    }

    public function test_index_lists_active_skills_without_content(): void
    {
        Skill::factory()->create(['name' => 'incident-priority', 'description' => 'How to prioritize.']);
        Skill::factory()->inactive()->create(['name' => 'draft-skill']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['skills']))
            ->getJson('/api/v1/skills')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'incident-priority', 'description' => 'How to prioritize.']);

        $body = $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['skills']))
            ->getJson('/api/v1/skills')
            ->assertStatus(200)
            ->json('data');

        $names = array_column($body, 'name');
        $this->assertContains('incident-priority', $names);
        $this->assertNotContains('draft-skill', $names);
        foreach ($body as $row) {
            $this->assertArrayNotHasKey('content', $row, 'the catalog must not ship skill bodies');
        }
    }

    public function test_index_lists_war_room_personas_as_role_entries(): void
    {
        WarRoomAgentConfig::factory()->create(['role_key' => 'sre', 'display_name' => 'SRE Engineer']);
        WarRoomAgentConfig::factory()->inactive()->create(['role_key' => 'ghost']);

        $body = $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['skills']))
            ->getJson('/api/v1/skills')
            ->assertStatus(200)
            ->json('data');

        $roles = array_filter($body, fn ($row) => ($row['domain'] ?? '') === 'role');
        $names = array_column($roles, 'name');
        $this->assertContains('role-sre', $names);
        $this->assertNotContains('role-ghost', $names);
        $sre = $roles[array_search('role-sre', $names, true)];
        $this->assertSame('SRE Engineer', $sre['display_name']);
    }

    public function test_show_returns_one_skill_content(): void
    {
        Skill::factory()->create(['name' => 'incident-priority', 'content' => "# Priority\n\nP1 first.\n"]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['skills']))
            ->getJson('/api/v1/skills/incident-priority')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'incident-priority')
            ->assertJsonPath('data.truncated', false)
            ->assertJsonPath('data.content', "# Priority\n\nP1 first.\n");
    }

    public function test_show_unknown_or_inactive_gets_404(): void
    {
        Skill::factory()->inactive()->create(['name' => 'draft-skill']);

        $auth = ['Authorization', 'Bearer '.$this->tokenWith(['skills'])];
        $this->withHeader(...$auth)->getJson('/api/v1/skills/ghost-skill')->assertStatus(404);
        $this->withHeader(...$auth)->getJson('/api/v1/skills/draft-skill')->assertStatus(404);
    }

    public function test_show_rejects_names_outside_the_skill_name_shape(): void
    {
        $auth = ['Authorization', 'Bearer '.$this->tokenWith(['skills'])];
        foreach (['a b', str_repeat('x', 65)] as $bad) {
            $this->withHeader(...$auth)
                ->getJson('/api/v1/skills/'.rawurlencode($bad))
                ->assertStatus(404, "name {$bad} must be rejected");
        }
    }

    public function test_oversized_skill_is_truncated_with_a_flag(): void
    {
        Skill::factory()->create(['name' => 'big-skill', 'content' => str_repeat('x', 200_000)]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['skills']))
            ->getJson('/api/v1/skills/big-skill')
            ->assertStatus(200)
            ->assertJsonPath('data.truncated', true)
            ->assertJsonPath('data.content', fn ($content) => mb_strlen((string) $content) <= 48_000);
    }

    public function test_show_role_persona_returns_system_prompt_and_skill_names(): void
    {
        $a = Skill::factory()->create(['name' => 'sev-analysis', 'content' => 'SECRET-BODY-ANALYSIS']);
        $b = Skill::factory()->create(['name' => 'sev-comms', 'content' => 'SECRET-BODY-COMMS']);
        WarRoomAgentConfig::factory()->create([
            'role_key' => 'sre',
            'system_prompt' => 'You are the SRE voice. Find the flapping service.',
        ])->skillRecords()->sync([$a->id, $b->id]);

        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['skills']))
            ->getJson('/api/v1/skills/role-sre')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'role-sre');

        $content = $res->json('data.content');
        $this->assertStringContainsString('You are the SRE voice. Find the flapping service.', $content);
        $this->assertStringContainsString('sev-analysis', $content);
        $this->assertStringContainsString('sev-comms', $content);
        $this->assertStringNotContainsString('SECRET-BODY', $content, 'a persona bundle must list skill NAMES, never ship their bodies');
    }

    public function test_show_unknown_persona_gets_404(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenWith(['skills']))
            ->getJson('/api/v1/skills/role-ghost')
            ->assertStatus(404);
    }
}
