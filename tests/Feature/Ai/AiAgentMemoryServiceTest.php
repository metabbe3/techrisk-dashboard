<?php

namespace Tests\Feature\Ai;

use App\Models\AiAgent;
use App\Models\AiAgentMemory;
use App\Services\Ai\AiAgentMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAgentMemoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_extracts_memory_bullets_from_happy_path_section(): void
    {
        $output = "Digest: 3 incidents.\n\n## Memory\n- LESSON: High-severity incidents cluster on Mondays\n- OUTCOME: Digest delivered, nothing critical open\n- NOTE: MTTR improving week over week\n";

        $rows = (new AiAgentMemoryService)->extractFromOutput($output);

        $this->assertSame([
            ['kind' => 'Lesson', 'content' => 'High-severity incidents cluster on Mondays'],
            ['kind' => 'Outcome', 'content' => 'Digest delivered, nothing critical open'],
            ['kind' => 'Note', 'content' => 'MTTR improving week over week'],
        ], $rows);
    }

    public function test_returns_empty_when_no_memory_section(): void
    {
        $this->assertSame([], (new AiAgentMemoryService)->extractFromOutput("Plain output.\n- LESSON: no section heading means no extraction"));
    }

    public function test_stops_at_next_heading(): void
    {
        $output = "Summary.\n\n## memory updates\n- NOTE: keeper\n\n## Next steps\n- LESSON: must not be captured";

        $rows = (new AiAgentMemoryService)->extractFromOutput($output);

        $this->assertSame([['kind' => 'Note', 'content' => 'keeper']], $rows);
    }

    public function test_handles_crlf_and_bold_markers(): void
    {
        $output = "Report.\r\n\r\n## Memory\r\n- **LESSON**: bold marker\r\n* OUTCOME: star bullet";

        $rows = (new AiAgentMemoryService)->extractFromOutput($output);

        $this->assertSame([
            ['kind' => 'Lesson', 'content' => 'bold marker'],
            ['kind' => 'Outcome', 'content' => 'star bullet'],
        ], $rows);
    }

    public function test_ignores_garbage_lines_and_blank_content(): void
    {
        $output = "## Memory\nsome prose line\n- LESSON:\n- GUESS: unknown kind\n- lesson: lowercase works";

        $rows = (new AiAgentMemoryService)->extractFromOutput($output);

        $this->assertSame([['kind' => 'Lesson', 'content' => 'lowercase works']], $rows);
    }

    public function test_record_feedback_stores_human_feedback_row(): void
    {
        $agent = AiAgent::create(['name' => 'Feedback Target', 'instructions' => 'x', 'frequency' => 'Manual']);
        $run = \App\Models\AiAgentRun::create([
            'agent_id' => $agent->id,
            'status' => 'Completed',
            'input' => 'x',
            'requested_at' => now(),
        ]);

        $row = (new AiAgentMemoryService)->recordFeedback($run, '  Keep the digest under 5 bullets.  ');

        $this->assertSame('Feedback', $row->kind->value);
        $this->assertSame($agent->id, $row->agent_id);
        $this->assertSame($run->id, $row->run_id);
        $this->assertSame('Keep the digest under 5 bullets.', $row->content);

        // Oversize feedback is clamped at store time.
        $long = (new AiAgentMemoryService)->recordFeedback($run, str_repeat('a', 2000));
        $this->assertSame(1000, mb_strlen($long->content));
    }

    public function test_recent_for_prompt_lists_author_kind_and_date_newest_first(): void
    {
        $agentA = AiAgent::create(['name' => 'Alpha', 'instructions' => 'x', 'frequency' => 'Manual']);
        $agentB = AiAgent::create(['name' => 'Beta', 'instructions' => 'x', 'frequency' => 'Manual']);

        AiAgentMemory::create(['agent_id' => $agentA->id, 'kind' => 'Lesson', 'content' => 'older']);
        $this->travel(1)->minutes();
        AiAgentMemory::create(['agent_id' => $agentB->id, 'kind' => 'Outcome', 'content' => 'newer']);

        $lines = explode("\n", (new AiAgentMemoryService)->recentForPrompt(25));

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('[Beta] Outcome: newer', $lines[0]);
        $this->assertStringContainsString('[Alpha] Lesson: older', $lines[1]);
        $this->assertSame(1, preg_match('/^- \d{4}-\d{2}-\d{2} \[/', $lines[0]));
    }

    public function test_recent_for_prompt_respects_limit(): void
    {
        $agent = AiAgent::create(['name' => 'Solo', 'instructions' => 'x', 'frequency' => 'Manual']);
        foreach (range(1, 5) as $i) {
            AiAgentMemory::create(['agent_id' => $agent->id, 'kind' => 'Note', 'content' => "n{$i}"]);
            $this->travel(1)->minutes();
        }

        $this->assertCount(2, explode("\n", (new AiAgentMemoryService)->recentForPrompt(2)));
    }

    public function test_org_tree_renders_hierarchy_and_marks_you(): void
    {
        $chief = AiAgent::create(['name' => 'Chief Analyst', 'instructions' => 'x', 'frequency' => 'Manual']);
        $deputy = AiAgent::create(['name' => 'Deputy', 'instructions' => 'x', 'frequency' => 'Manual', 'reports_to_agent_id' => $chief->id]);
        $self = AiAgent::create(['name' => 'Worker', 'instructions' => 'x', 'frequency' => 'Manual', 'reports_to_agent_id' => $deputy->id]);

        $tree = (new AiAgentMemoryService)->orgTreeForPrompt($self);

        $lines = explode("\n", $tree);
        $this->assertSame('- Chief Analyst', $lines[0]);
        $this->assertSame('  - Deputy', $lines[1]);
        $this->assertSame('    - Worker (you)', $lines[2]);
    }

    public function test_org_tree_empty_when_flat_or_disabled(): void
    {
        $service = new AiAgentMemoryService;

        $lone = AiAgent::create(['name' => 'Lone', 'instructions' => 'x', 'frequency' => 'Manual']);
        $this->assertSame('', $service->orgTreeForPrompt($lone));

        $boss = AiAgent::create(['name' => 'Boss', 'instructions' => 'x', 'frequency' => 'Manual']);
        $sub = AiAgent::create(['name' => 'Sub', 'instructions' => 'x', 'frequency' => 'Manual', 'reports_to_agent_id' => $boss->id, 'enabled' => false]);
        $this->assertSame('', $service->orgTreeForPrompt($boss));
    }
}
