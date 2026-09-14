<?php

namespace Tests\Feature\Ai;

use App\Models\ChatPlanSubtask;
use App\Services\Ai\PlanMode\PlanModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanGapAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.base_url' => 'http://gateway.test',
            'ai.api_key' => 'test-key',
        ]);

        Cache::flush();
    }

    private function seedCompletedSubtask(string $planId, string $conversationId): void
    {
        ChatPlanSubtask::create([
            'plan_id' => $planId,
            'conversation_id' => $conversationId,
            'subtask_index' => 0,
            'description' => 'Analyze MTTR trend',
            'persona_key' => 'data_analyst',
            'status' => 'completed',
            'result' => 'MTTR stable',
        ]);
    }

    private function fakeGapResponse(float $coverageScore, bool $researchNeeded): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'coverage_score' => $coverageScore,
                        'gaps' => [['topic' => 'Financial impact', 'reason' => 'Not covered', 'suggested_research' => 'Analyze fund loss']],
                        'research_needed' => $researchNeeded,
                    ])]],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
            ]),
        ]);
    }

    public function test_cached_research_flag_is_filtered_by_coverage_score(): void
    {
        // Model says research is needed, but coverage (0.9) is already above the
        // 0.8 threshold — the cached flag the streamers read must say false, or
        // they announce research the AnalyzePlanGaps job never runs.
        $planId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        $this->seedCompletedSubtask($planId, $conversationId);
        $this->fakeGapResponse(coverageScore: 0.9, researchNeeded: true);

        $result = app(PlanModeService::class)->analyzeGaps($planId, $conversationId, 'What drove MTTR?');

        $cached = Cache::get("plan_gap_analysis:{$planId}");
        $this->assertSame(false, $cached['research_needed']);
        $this->assertFalse($result->deepResearchNeeded);
    }

    public function test_cached_research_flag_stays_true_when_coverage_is_low(): void
    {
        $planId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        $this->seedCompletedSubtask($planId, $conversationId);
        $this->fakeGapResponse(coverageScore: 0.5, researchNeeded: true);

        $result = app(PlanModeService::class)->analyzeGaps($planId, $conversationId, 'What drove MTTR?');

        $cached = Cache::get("plan_gap_analysis:{$planId}");
        $this->assertSame(true, $cached['research_needed']);
        $this->assertTrue($result->deepResearchNeeded);
    }
}
