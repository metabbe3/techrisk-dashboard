<?php

namespace Tests\Feature\Ai;

use App\Models\AiAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAgentCycleGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dependency_cycle_is_detected(): void
    {
        $a = AiAgent::create(['name' => 'A', 'instructions' => 'x', 'frequency' => 'Manual']);
        $b = AiAgent::create(['name' => 'B', 'instructions' => 'x', 'frequency' => 'Manual', 'depends_on_agent_id' => $a->id]);

        // Pointing A at B would close the loop B -> A -> B.
        $this->assertTrue(AiAgent::createsDependencyCycle('depends_on_agent_id', $b->id, $a->id));
    }

    public function test_valid_chain_is_not_a_cycle(): void
    {
        $x = AiAgent::create(['name' => 'X', 'instructions' => 'x', 'frequency' => 'Manual']);
        $y = AiAgent::create(['name' => 'Y', 'instructions' => 'x', 'frequency' => 'Manual', 'depends_on_agent_id' => $x->id]);
        $z = AiAgent::create(['name' => 'Z', 'instructions' => 'x', 'frequency' => 'Manual', 'depends_on_agent_id' => $y->id]);

        // Z running after X: the walk X -> (null) ends without meeting Z. Fine.
        $this->assertFalse(AiAgent::createsDependencyCycle('depends_on_agent_id', $x->id, $z->id));

        // But X running after Y IS a cycle: Y already runs after X (X -> Y -> X).
        // So is X running after Z — the whole chain Z -> Y -> X leads back to X.
        $this->assertTrue(AiAgent::createsDependencyCycle('depends_on_agent_id', $y->id, $x->id));
        $this->assertTrue(AiAgent::createsDependencyCycle('depends_on_agent_id', $z->id, $x->id));
    }

    public function test_reports_to_walked_via_its_own_column(): void
    {
        $a = AiAgent::create(['name' => 'A', 'instructions' => 'x', 'frequency' => 'Manual']);
        // B runs after A (depends_on chain only) — no reports_to relation anywhere yet.
        $b = AiAgent::create(['name' => 'B', 'instructions' => 'x', 'frequency' => 'Manual', 'depends_on_agent_id' => $a->id]);

        // A reporting to B is fine: the reports_to walk from B ends immediately.
        $this->assertFalse(AiAgent::createsDependencyCycle('reports_to_agent_id', $b->id, $a->id));

        // But A running after B is a depends_on cycle (B already runs after A).
        $this->assertTrue(AiAgent::createsDependencyCycle('depends_on_agent_id', $b->id, $a->id));
    }

    public function test_null_ignore_id_never_cycles(): void
    {
        $a = AiAgent::create(['name' => 'A', 'instructions' => 'x', 'frequency' => 'Manual']);

        // Create-time: the agent being created has no id yet.
        $this->assertFalse(AiAgent::createsDependencyCycle('depends_on_agent_id', $a->id, null));
        $this->assertFalse(AiAgent::createsDependencyCycle('depends_on_agent_id', null, $a->id));
    }
}
