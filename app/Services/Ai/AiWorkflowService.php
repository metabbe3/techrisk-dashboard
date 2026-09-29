<?php

namespace App\Services\Ai;

use App\Models\AiAgent;
use App\Models\AiWorkflow;
use Illuminate\Support\Facades\DB;

/**
 * A workflow is an ordered agent list rendered as a depends_on chain:
 * step n runs after step n-1. The existing chain propagation (RunAiAgentJob
 * hands off on success) does the rest — no separate workflow executor.
 */
class AiWorkflowService
{
    /**
     * Persist the ordered agent ids as steps, then (re)wire the chain.
     * Delete-and-insert: simple, and steps have no state worth preserving.
     *
     * @param  array<int, string>  $agentIds  ordered, non-empty
     */
    public function saveSteps(AiWorkflow $workflow, array $agentIds): void
    {
        $agentIds = array_values(array_unique(array_filter($agentIds)));

        DB::transaction(function () use ($workflow, $agentIds): void {
            $workflow->steps()->delete();

            foreach ($agentIds as $position => $agentId) {
                $workflow->steps()->create(['agent_id' => $agentId, 'position' => $position]);
            }

            $this->syncChain($workflow);
        });
    }

    /**
     * Linear chain: step 1 runs standalone, each next step depends on the
     * previous. Linear ⇒ cycles impossible, so no cycle guard needed here.
     */
    public function syncChain(AiWorkflow $workflow): void
    {
        $steps = $workflow->steps()->orderBy('position')->get();

        $previous = null;
        foreach ($steps as $step) {
            if ($step->agent_id !== $previous) {
                AiAgent::query()->whereKey($step->agent_id)->update(['depends_on_agent_id' => $previous]);
            }
            $previous = $step->agent_id;
        }
    }

    /**
     * Re-chain after a step disappears (agent deleted cascades the step):
     * bridge the gap so downstream agents still follow the survivor.
     */
    public function healChain(AiWorkflow $workflow): void
    {
        $this->syncChain($workflow);
    }
}
