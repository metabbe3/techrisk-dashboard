<?php

namespace Tests\Feature\Ai;

use App\Models\AiAgent;
use App\Models\AiWorkflow;
use App\Services\Ai\AiWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    private function agent(string $name): AiAgent
    {
        return AiAgent::create(['name' => $name, 'instructions' => 'x', 'frequency' => 'Manual']);
    }

    public function test_save_steps_wires_linear_chain(): void
    {
        $a = $this->agent('A');
        $b = $this->agent('B');
        $c = $this->agent('C');
        $workflow = AiWorkflow::create(['name' => 'Pipeline']);

        app(AiWorkflowService::class)->saveSteps($workflow, [$a->id, $b->id, $c->id]);

        $this->assertSame(3, $workflow->steps()->count());
        $this->assertNull($a->refresh()->depends_on_agent_id);
        $this->assertSame($a->id, $b->refresh()->depends_on_agent_id);
        $this->assertSame($b->id, $c->refresh()->depends_on_agent_id);
    }

    public function test_reorder_rewires_chain(): void
    {
        $a = $this->agent('A');
        $b = $this->agent('B');
        $workflow = AiWorkflow::create(['name' => 'Swap']);

        $service = app(AiWorkflowService::class);
        $service->saveSteps($workflow, [$a->id, $b->id]);
        $service->saveSteps($workflow, [$b->id, $a->id]);

        $this->assertNull($b->refresh()->depends_on_agent_id);
        $this->assertSame($b->id, $a->refresh()->depends_on_agent_id);
    }

    public function test_deleting_middle_agent_heals_chain(): void
    {
        $a = $this->agent('A');
        $b = $this->agent('B');
        $c = $this->agent('C');
        $workflow = AiWorkflow::create(['name' => 'Heal']);

        $service = app(AiWorkflowService::class);
        $service->saveSteps($workflow, [$a->id, $b->id, $c->id]);

        // Agent deleted -> step cascades away; healing bridges the gap.
        $b->delete();
        $service->healChain($workflow->refresh());

        $this->assertSame([$a->id, $c->id], $workflow->steps()->orderBy('position')->pluck('agent_id')->all());
        $this->assertSame($a->id, $c->refresh()->depends_on_agent_id);
    }

    public function test_duplicate_agent_ids_collapse(): void
    {
        $a = $this->agent('A');
        $workflow = AiWorkflow::create(['name' => 'Dedup']);

        app(AiWorkflowService::class)->saveSteps($workflow, [$a->id, $a->id]);

        $this->assertSame(1, $workflow->steps()->count());
        $this->assertNull($a->refresh()->depends_on_agent_id);
    }
}
