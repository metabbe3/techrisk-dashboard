<?php

namespace Tests\Unit\Services\WarRoom;

use App\Models\Incident;
use App\Models\WarRoomAgentConfig;
use App\Models\WarRoomMessage;
use App\Models\WarRoomSession;
use App\Services\Ai\PromptOptimizer;
use App\Services\Skills\SkillPromptBuilder;
use App\Services\WarRoom\AgentPromptBuilder;
use Database\Factories\WarRoomAgentConfigFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AgentPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    private AgentPromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        // PromptOptimizer checks config; disable so it does not rewrite prompts during tests
        config(['ai.prompt_optimization.enabled' => false]);

        // Default: SkillPromptBuilder returns empty string (no skills assigned)
        $skillMock = $this->createMock(SkillPromptBuilder::class);
        $skillMock->method('buildSkillPrompt')->willReturn('');
        $this->app->instance(SkillPromptBuilder::class, $skillMock);

        $this->builder = new AgentPromptBuilder;
    }

    // ------------------------------------------------------------------
    // Helper methods
    // ------------------------------------------------------------------

    private function createSession(array $overrides = []): WarRoomSession
    {
        $defaults = [
            'incident_context' => ['Sample incident context for testing'],
            'user_instructions' => null,
        ];

        return WarRoomSession::factory()->create(array_merge($defaults, $overrides));
    }

    private function createAgentConfig(string $roleKey, array $overrides = []): WarRoomAgentConfig
    {
        $defaults = [
            'role_key' => $roleKey,
            'display_name' => ucfirst($roleKey).' Agent',
            'system_prompt' => "You are the {$roleKey} agent. Analyze the incident.",
            'skills' => ['Analysis', 'Investigation'],
            'is_active' => true,
        ];

        return WarRoomAgentConfigFactory::new()->create(array_merge($defaults, $overrides));
    }

    private function seedAgentConfigCache(): void
    {
        // WarRoomAgentConfig::findByRole() uses cache; clear stale entries
        Cache::forget('war_room:agent_configs:keyed');
    }

    // ------------------------------------------------------------------
    // 1. buildAgentPrompt — base prompt
    // ------------------------------------------------------------------

    public function test_build_agent_prompt_includes_base_prompt(): void
    {
        $config = $this->createAgentConfig('sre', [
            'system_prompt' => 'You are an SRE expert.',
        ]);
        $this->seedAgentConfigCache();

        $session = $this->createSession();

        $result = $this->builder->buildAgentPrompt('sre', $session);

        $this->assertStringContainsString('You are an SRE expert.', $result);
    }

    // ------------------------------------------------------------------
    // 2. buildAgentPrompt — uses default prompt when no config exists
    // ------------------------------------------------------------------

    public function test_build_agent_prompt_uses_default_prompt_when_no_config(): void
    {
        // No WarRoomAgentConfig record for 'sre'
        Cache::forget('war_room:agent_configs:keyed');

        $session = $this->createSession();

        $result = $this->builder->buildAgentPrompt('sre', $session);

        // getDefaultPrompt() falls back to getDefaultAgents() collection
        $defaultAgents = AgentPromptBuilder::getDefaultAgents();
        $srePrompt = collect($defaultAgents)->firstWhere('role_key', 'sre')['system_prompt'];
        $this->assertStringContainsString($srePrompt, $result);
    }

    // ------------------------------------------------------------------
    // 3. buildAgentPrompt — incident context
    // ------------------------------------------------------------------

    public function test_build_agent_prompt_includes_incident_context(): void
    {
        $this->createAgentConfig('sre');
        $this->seedAgentConfigCache();

        $session = $this->createSession([
            'incident_context' => ['Incident occurred on 2026-05-01 at 14:00 UTC'],
        ]);

        $result = $this->builder->buildAgentPrompt('sre', $session);

        $this->assertStringContainsString('Incident occurred on 2026-05-01 at 14:00 UTC', $result);
        $this->assertStringContainsString('## Incident Data', $result);
    }

    public function test_build_agent_prompt_handles_string_incident_context(): void
    {
        $this->createAgentConfig('sre');
        $this->seedAgentConfigCache();

        $session = $this->createSession([
            'incident_context' => 'Plain string incident context',
        ]);

        $result = $this->builder->buildAgentPrompt('sre', $session);

        $this->assertStringContainsString('Plain string incident context', $result);
    }

    public function test_build_agent_prompt_handles_null_incident_context(): void
    {
        $this->createAgentConfig('sre');
        $this->seedAgentConfigCache();

        $session = $this->createSession([
            'incident_context' => null,
        ]);

        $result = $this->builder->buildAgentPrompt('sre', $session);

        $this->assertStringContainsString('## Incident Data', $result);
    }

    // ------------------------------------------------------------------
    // 4. buildAgentPrompt — user instructions
    // ------------------------------------------------------------------

    public function test_build_agent_prompt_includes_user_instructions(): void
    {
        $this->createAgentConfig('sre');
        $this->seedAgentConfigCache();

        $session = $this->createSession([
            'user_instructions' => 'Focus on database latency issues specifically.',
        ]);

        $result = $this->builder->buildAgentPrompt('sre', $session);

        $this->assertStringContainsString('## User Instructions', $result);
        $this->assertStringContainsString('Focus on database latency issues specifically.', $result);
    }

    public function test_build_agent_prompt_omits_user_instructions_when_empty(): void
    {
        $this->createAgentConfig('sre');
        $this->seedAgentConfigCache();

        $session = $this->createSession([
            'user_instructions' => null,
        ]);

        $result = $this->builder->buildAgentPrompt('sre', $session);

        $this->assertStringNotContainsString('## User Instructions', $result);
    }

    // ------------------------------------------------------------------
    // 5. buildAgentPrompt — cross-incident analysis
    // ------------------------------------------------------------------

    public function test_build_agent_prompt_includes_cross_incident_analysis_for_multiple_incidents(): void
    {
        $this->createAgentConfig('sre');
        $this->seedAgentConfigCache();

        $incident1 = Incident::factory()->create();
        $incident2 = Incident::factory()->create();
        $session = $this->createSession(['incident_id' => $incident1->id]);
        $session->incidents()->attach([$incident1->id, $incident2->id]);

        // Reload to get the incidents relationship populated
        $session->load('incidents');

        $result = $this->builder->buildAgentPrompt('sre', $session);

        $this->assertStringContainsString('## Cross-Incident Analysis', $result);
        $this->assertStringContainsString('Multiple incidents are provided above', $result);
    }

    public function test_build_agent_prompt_omits_cross_incident_analysis_for_single_incident(): void
    {
        $this->createAgentConfig('sre');
        $this->seedAgentConfigCache();

        $session = $this->createSession();
        // Single incident (the factory-created one)
        $session->load('incidents');

        $result = $this->builder->buildAgentPrompt('sre', $session);

        $this->assertStringNotContainsString('## Cross-Incident Analysis', $result);
    }

    // ------------------------------------------------------------------
    // 6. buildAgentPrompt — skills section
    // ------------------------------------------------------------------

    public function test_build_agent_prompt_includes_capabilities_when_no_skill_prompt(): void
    {
        $this->createAgentConfig('sre', [
            'skills' => ['SLA Management', 'Incident Response'],
        ]);
        $this->seedAgentConfigCache();

        $session = $this->createSession();

        $result = $this->builder->buildAgentPrompt('sre', $session);

        $this->assertStringContainsString('## Capabilities', $result);
        $this->assertStringContainsString('- SLA Management', $result);
        $this->assertStringContainsString('- Incident Response', $result);
    }

    public function test_build_agent_prompt_prefers_skill_prompt_over_capabilities(): void
    {
        $config = $this->createAgentConfig('sre', [
            'skills' => ['SLA Management'],
        ]);
        $this->seedAgentConfigCache();

        // Override SkillPromptBuilder to return a non-empty skill prompt
        $skillMock = $this->createMock(SkillPromptBuilder::class);
        $skillMock->method('buildSkillPrompt')->willReturn('## Assigned Skills & Methodologies\nCustom skill content');
        $this->app->instance(SkillPromptBuilder::class, $skillMock);

        $builder = new AgentPromptBuilder;
        $session = $this->createSession();

        $result = $builder->buildAgentPrompt('sre', $session);

        $this->assertStringContainsString('Custom skill content', $result);
        // Capabilities should NOT appear since skill prompt is non-empty
        $this->assertStringNotContainsString('## Capabilities', $result);
    }

    // ------------------------------------------------------------------
    // 7. buildAgentPrompt — prompt optimizer integration
    // ------------------------------------------------------------------

    public function test_build_agent_prompt_applies_prompt_optimizer_when_enabled(): void
    {
        config(['ai.prompt_optimization.enabled' => true]);

        $optimizerMock = $this->createMock(PromptOptimizer::class);
        $optimizerMock->method('optimize')->willReturnCallback(function (string $prompt) {
            return $prompt."\n<!-- OPTIMIZED -->";
        });
        $this->app->instance(PromptOptimizer::class, $optimizerMock);

        $this->createAgentConfig('sre');
        $this->seedAgentConfigCache();

        $session = $this->createSession();
        $result = $this->builder->buildAgentPrompt('sre', $session);

        $this->assertStringContainsString('<!-- OPTIMIZED -->', $result);
    }

    // ------------------------------------------------------------------
    // 8. buildRoundUserMessage — round 1, single incident
    // ------------------------------------------------------------------

    public function test_build_round_user_message_round_1_single_incident(): void
    {
        $this->createAgentConfig('sre', ['display_name' => 'SRE (Site Reliability)']);
        $this->seedAgentConfigCache();

        $session = $this->createSession();
        $session->load('incidents');

        $result = $this->builder->buildRoundUserMessage($session, 'sre', 1);

        $this->assertStringContainsString('As SRE (Site Reliability)', $result);
        $this->assertStringContainsString('primary analysis', $result);
        $this->assertStringContainsString('## Key Findings & Discussion Points', $result);
        $this->assertStringContainsString('3-5 bullet points', $result);
    }

    public function test_build_round_user_message_round_1_multi_incident(): void
    {
        $this->createAgentConfig('sre', ['display_name' => 'SRE (Site Reliability)']);
        $this->seedAgentConfigCache();

        $incident1 = Incident::factory()->create();
        $incident2 = Incident::factory()->create();
        $session = $this->createSession(['incident_id' => $incident1->id]);
        $session->incidents()->attach([$incident1->id, $incident2->id]);
        $session->load('incidents');

        $result = $this->builder->buildRoundUserMessage($session, 'sre', 1);

        $this->assertStringContainsString('multiple incidents', $result);
        $this->assertStringContainsString('Cross-Incident Analysis Section', $result);
        $this->assertStringContainsString('Per-Incident Analysis', $result);
    }

    public function test_build_round_user_message_round_1_falls_back_to_role_name(): void
    {
        // No agent config created — should fall back to ucfirst($role)
        Cache::forget('war_room:agent_configs:keyed');

        $session = $this->createSession();
        $session->load('incidents');

        $result = $this->builder->buildRoundUserMessage($session, 'custom_role', 1);

        $this->assertStringContainsString('As Custom_role', $result);
    }

    // ------------------------------------------------------------------
    // 9. buildRoundUserMessage — round 2+
    // ------------------------------------------------------------------

    public function test_build_round_user_message_round_2_includes_previous_summary(): void
    {
        $this->createAgentConfig('sre', ['display_name' => 'SRE (Site Reliability)']);
        $this->createAgentConfig('tech_risk', ['display_name' => 'Tech Risk Analyst']);
        $this->seedAgentConfigCache();

        $session = $this->createSession();
        $session->load('incidents');

        // Seed round 1 messages with key findings sections
        WarRoomMessage::factory()->completed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'content' => "Full SRE analysis here.\n\n## Key Findings & Discussion Points\n\n- Finding 1: MTTR was 4 hours\n- Finding 2: No alerting was in place\n\n## Extra Section\nMore content.",
        ]);
        WarRoomMessage::factory()->completed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'tech_risk',
            'content' => "Full risk analysis.\n\n## Key Findings & Discussion Points\n\n- Risk score: High\n- Financial exposure: IDR 500M",
        ]);

        $result = $this->builder->buildRoundUserMessage($session, 'sre', 2);

        $this->assertStringContainsString('Round 1 Key Findings', $result);
        $this->assertStringContainsString('Round 2', $result);
        $this->assertStringContainsString('SRE (Site Reliability)', $result);
        $this->assertStringContainsString('Tech Risk Analyst', $result);
        $this->assertStringContainsString('MTTR was 4 hours', $result);
        $this->assertStringContainsString('Financial exposure', $result);
    }

    // ------------------------------------------------------------------
    // 10. buildPreviousRoundSummary — extracts key findings
    // ------------------------------------------------------------------

    public function test_build_previous_round_summary_extracts_key_findings(): void
    {
        $this->createAgentConfig('sre', ['display_name' => 'SRE (Site Reliability)']);
        $this->seedAgentConfigCache();

        $session = $this->createSession();

        $content = <<<'MARKDOWN'
        Full analysis with lots of details here.

        ## Key Findings & Discussion Points

        - MTTR exceeded SLA by 200%
        - No monitoring in place for this service
        - Root cause: misconfigured connection pool

        ## Extra Section
        More details here.
        MARKDOWN;

        WarRoomMessage::factory()->completed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'content' => $content,
        ]);

        $result = $this->builder->buildPreviousRoundSummary($session, 1);

        $this->assertStringContainsString('MTTR exceeded SLA', $result);
        $this->assertStringContainsString('SRE (Site Reliability)', $result);
        // Should stop at the next ## heading
        $this->assertStringNotContainsString('Extra Section', $result);
    }

    public function test_build_previous_round_summary_skips_non_completed_messages(): void
    {
        $this->createAgentConfig('sre', ['display_name' => 'SRE (Site Reliability)']);
        $this->seedAgentConfigCache();

        $session = $this->createSession();

        // A pending message should be excluded
        WarRoomMessage::factory()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'status' => 'pending',
            'content' => null,
        ]);

        $result = $this->builder->buildPreviousRoundSummary($session, 1);

        // No completed messages — result should be empty
        $this->assertEquals('', $result);
    }

    // ------------------------------------------------------------------
    // 11. extractKeyFindings — tested indirectly via buildPreviousRoundSummary
    //     (private method, but we cover its behavior through public methods)
    // ------------------------------------------------------------------

    public function test_extract_key_findings_matches_alternate_markers(): void
    {
        $this->createAgentConfig('dba', ['display_name' => 'IDC DBA']);
        $this->seedAgentConfigCache();

        $session = $this->createSession();

        // Use "## Key Findings" (shorter marker)
        $content = str_repeat('Body content. ', 50)."\n\n## Key Findings\n\n- Query took 45 seconds\n- Index was missing on orders table\n- Replication lag detected at 300s";

        WarRoomMessage::factory()->completed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'dba',
            'content' => $content,
        ]);

        $result = $this->builder->buildPreviousRoundSummary($session, 1);

        $this->assertStringContainsString('Query took 45 seconds', $result);
    }

    // ------------------------------------------------------------------
    // 12. extractKeyFindings — falls back to tail section
    // ------------------------------------------------------------------

    public function test_extract_key_findings_falls_back_to_tail_section(): void
    {
        $this->createAgentConfig('qa', ['display_name' => 'QA Engineer']);
        $this->seedAgentConfigCache();

        $session = $this->createSession();

        // Content without any key findings markers
        $content = <<<'MARKDOWN'
        ## Analysis

        This is a long analysis section with many details.

        ## Testing Gaps

        No integration tests covered the payment flow.
        The staging environment was not configured to match production.
        Edge cases around timeout handling were never tested.

        ## Recommendations

        - Add integration tests for payment flow
        - Align staging with production config
        - Add timeout edge case tests
        MARKDOWN;

        WarRoomMessage::factory()->completed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'qa',
            'content' => $content,
        ]);

        $result = $this->builder->buildPreviousRoundSummary($session, 1);

        // Should contain the auto-extracted notice
        $this->assertStringContainsString('*[Auto-extracted from full analysis', $result);
        $this->assertStringContainsString('QA Engineer', $result);
    }

    // ------------------------------------------------------------------
    // 13. extractKeyFindings — skips marker match if section too short
    // ------------------------------------------------------------------

    public function test_extract_key_findings_skips_short_marker_match(): void
    {
        $this->createAgentConfig('dev_fe', ['display_name' => 'Dev Frontend']);
        $this->seedAgentConfigCache();

        $session = $this->createSession();

        // Marker match exists but the content after it is under 50 chars,
        // so it should fall back to the tail section.
        $content = str_repeat('Detailed analysis paragraph. ', 30)."\n\n## Key Findings & Discussion Points\n\nOK\n\n## Recommendations\n- Fix the bug";

        WarRoomMessage::factory()->completed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'dev_fe',
            'content' => $content,
        ]);

        $result = $this->builder->buildPreviousRoundSummary($session, 1);

        // Falls back to auto-extracted tail (since the key findings section was too short)
        $this->assertStringContainsString('*[Auto-extracted from full analysis', $result);
    }

    // ------------------------------------------------------------------
    // 14. getDefaultAgents — returns 15 agents
    // ------------------------------------------------------------------

    public function test_get_default_agents_returns_15_agents(): void
    {
        $agents = AgentPromptBuilder::getDefaultAgents();

        $this->assertCount(15, $agents);
    }

    // ------------------------------------------------------------------
    // 15. getDefaultAgents — each agent has required structure
    // ------------------------------------------------------------------

    public function test_get_default_agents_structure(): void
    {
        $agents = AgentPromptBuilder::getDefaultAgents();

        $requiredKeys = ['role_key', 'display_name', 'description', 'skills', 'icon', 'color', 'system_prompt'];

        foreach ($agents as $index => $agent) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $agent,
                    "Agent at index {$index} ({$agent['role_key']}) is missing key: {$key}"
                );
            }

            $this->assertNotEmpty($agent['role_key'], "Agent at index {$index} has empty role_key");
            $this->assertNotEmpty($agent['display_name'], "Agent at index {$index} has empty display_name");
            $this->assertNotEmpty($agent['system_prompt'], "Agent at index {$index} has empty system_prompt");
            $this->assertIsArray($agent['skills'], "Agent at index {$index} skills should be an array");
            $this->assertNotEmpty($agent['skills'], "Agent at index {$index} has empty skills array");
        }
    }

    public function test_get_default_agents_has_expected_roles(): void
    {
        $agents = AgentPromptBuilder::getDefaultAgents();
        $roleKeys = array_column($agents, 'role_key');

        $expectedRoles = [
            'sre', 'ts', 'dba', 'system', 'tech_risk',
            'dev_be', 'dev_fe', 'qa', 'pm', 'pd',
            'security', 'compliance', 'data_analyst', 'devils_advocate', 'moderator',
        ];

        foreach ($expectedRoles as $role) {
            $this->assertContains(
                $role,
                $roleKeys,
                "Expected role '{$role}' not found in default agents"
            );
        }
    }

    public function test_get_default_agents_roles_are_unique(): void
    {
        $agents = AgentPromptBuilder::getDefaultAgents();
        $roleKeys = array_column($agents, 'role_key');

        $this->assertCount(15, $roleKeys, 'Expected exactly 15 role keys');
        $this->assertCount(15, array_unique($roleKeys), 'Each role_key should be unique');
    }

    // ------------------------------------------------------------------
    // 16. buildModeratorUserMessage
    // ------------------------------------------------------------------

    public function test_build_moderator_user_message_assembles_all_rounds(): void
    {
        $this->createAgentConfig('sre', ['display_name' => 'SRE (Site Reliability)']);
        $this->createAgentConfig('tech_risk', ['display_name' => 'Tech Risk Analyst']);
        $this->seedAgentConfigCache();

        $session = $this->createSession(['user_instructions' => null]);
        $session->load('incidents');

        WarRoomMessage::factory()->completed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'content' => 'SRE analysis content here.',
        ]);
        WarRoomMessage::factory()->completed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'tech_risk',
            'content' => 'Tech risk analysis content here.',
        ]);
        WarRoomMessage::factory()->completed()->create([
            'session_id' => $session->id,
            'round' => 2,
            'agent_role' => 'sre',
            'content' => 'SRE round 2 response.',
        ]);

        $result = $this->builder->buildModeratorUserMessage($session);

        $this->assertStringContainsString('Agent analyses from all rounds', $result);
        $this->assertStringContainsString('### Round 1', $result);
        $this->assertStringContainsString('### Round 2', $result);
        $this->assertStringContainsString('SRE analysis content here', $result);
        $this->assertStringContainsString('Tech risk analysis content here', $result);
        $this->assertStringContainsString('SRE round 2 response', $result);
        $this->assertStringContainsString('Synthesize into a final report', $result);
    }

    public function test_build_moderator_user_message_includes_user_instructions(): void
    {
        $this->createAgentConfig('sre', ['display_name' => 'SRE Agent']);
        $this->seedAgentConfigCache();

        $session = $this->createSession([
            'user_instructions' => 'Pay special attention to SLA breaches.',
        ]);

        WarRoomMessage::factory()->completed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'content' => 'Some analysis.',
        ]);

        $result = $this->builder->buildModeratorUserMessage($session);

        $this->assertStringContainsString('## User Instructions', $result);
        $this->assertStringContainsString('Pay special attention to SLA breaches', $result);
    }

    public function test_build_moderator_user_message_includes_partial_notice(): void
    {
        $this->createAgentConfig('sre', ['display_name' => 'SRE Agent']);
        $this->createAgentConfig('tech_risk', ['display_name' => 'Risk Agent']);
        $this->seedAgentConfigCache();

        $session = $this->createSession(['user_instructions' => null]);

        // One completed, one failed
        WarRoomMessage::factory()->completed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'content' => 'SRE analysis.',
        ]);
        WarRoomMessage::factory()->failed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'tech_risk',
        ]);

        $result = $this->builder->buildModeratorUserMessage($session);

        $this->assertStringContainsString('partial data', $result);
        $this->assertStringContainsString('1 of 2', $result);
        $this->assertStringContainsString('Agents that failed in this round', $result);
        $this->assertStringContainsString('Risk Agent', $result);
    }

    public function test_build_moderator_user_message_no_partial_notice_when_all_complete(): void
    {
        $this->createAgentConfig('sre', ['display_name' => 'SRE Agent']);
        $this->seedAgentConfigCache();

        $session = $this->createSession(['user_instructions' => null]);

        WarRoomMessage::factory()->completed()->create([
            'session_id' => $session->id,
            'round' => 1,
            'agent_role' => 'sre',
            'content' => 'Analysis.',
        ]);

        $result = $this->builder->buildModeratorUserMessage($session);

        $this->assertStringNotContainsString('partial data', $result);
    }

    // ------------------------------------------------------------------
    // 17. buildModeratorPrompt
    // ------------------------------------------------------------------

    public function test_build_moderator_prompt_returns_static_prompt(): void
    {
        $result = $this->builder->buildModeratorPrompt();

        $this->assertStringContainsString('Senior Technical Report Synthesizer', $result);
        $this->assertStringContainsString('Root Cause Analysis', $result);
        $this->assertStringContainsString('Prevention Strategy', $result);
    }

    // ------------------------------------------------------------------
    // 18. Edge cases for buildAgentPrompt skills handling
    // ------------------------------------------------------------------

    public function test_build_agent_prompt_handles_array_skills_with_skill_key(): void
    {
        $this->createAgentConfig('sre', [
            'skills' => [
                ['skill' => 'SLA Management'],
                ['skill' => 'Incident Response'],
            ],
        ]);
        $this->seedAgentConfigCache();

        $session = $this->createSession();

        $result = $this->builder->buildAgentPrompt('sre', $session);

        $this->assertStringContainsString('## Capabilities', $result);
        $this->assertStringContainsString('- SLA Management', $result);
        $this->assertStringContainsString('- Incident Response', $result);
    }

    public function test_build_agent_prompt_omits_capabilities_when_skills_empty(): void
    {
        $this->createAgentConfig('sre', [
            'skills' => [],
        ]);
        $this->seedAgentConfigCache();

        $session = $this->createSession();

        $result = $this->builder->buildAgentPrompt('sre', $session);

        $this->assertStringNotContainsString('## Capabilities', $result);
    }

    public function test_build_agent_prompt_filters_empty_skill_strings(): void
    {
        $this->createAgentConfig('sre', [
            'skills' => ['', 'Valid Skill', ''],
        ]);
        $this->seedAgentConfigCache();

        $session = $this->createSession();

        $result = $this->builder->buildAgentPrompt('sre', $session);

        $this->assertStringContainsString('- Valid Skill', $result);
        $this->assertEquals(1, substr_count($result, '- Valid Skill'));
    }
}
