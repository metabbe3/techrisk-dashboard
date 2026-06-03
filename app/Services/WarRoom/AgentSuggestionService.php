<?php

namespace App\Services\WarRoom;

use App\Models\Incident;
use App\Models\WarRoomAgentConfig;
use App\Services\Ai\AiTextService;

class AgentSuggestionService
{
    public function __construct(
        private AiTextService $ai,
    ) {}

    public function suggestAgents(array $incidentIds, ?string $userInstructions = null): array
    {
        if (! config('ai.war_room.agent_suggestion.enabled', true)) {
            return [];
        }

        $incidents = Incident::with(['labels', 'pic'])->whereIn('id', $incidentIds)->get();

        if ($incidents->isEmpty()) {
            return [];
        }

        $agents = WarRoomAgentConfig::query()
            ->where('is_active', true)
            ->where('role_key', '!=', 'moderator')
            ->orderBy('sort_order')
            ->get();

        if ($agents->isEmpty()) {
            return [];
        }

        $incidentSummary = $this->buildIncidentSummary($incidents);
        $agentCatalog = $this->buildAgentCatalog($agents);

        $system = $this->buildSystemPrompt();
        $user = $this->buildUserPrompt($incidentSummary, $agentCatalog, $userInstructions);

        $model = config('ai.war_room.agent_suggestion.model', 'FAST-MODEL');

        $result = $this->ai->callAiForJson(
            'agent_suggestion',
            $model,
            $system,
            $user,
            ['suggested_agents' => []]
        );

        $suggested = $result['suggested_agents'] ?? [];

        $validKeys = $agents->pluck('role_key')->toArray();

        return array_values(array_filter($suggested, fn ($key) => is_string($key) && in_array($key, $validKeys)));
    }

    private function buildIncidentSummary($incidents): string
    {
        return $incidents->map(function (Incident $inc, $i) {
            $parts = [($i + 1).". [{$inc->no}] {$inc->title}"];
            $parts[] = "   Severity: {$inc->severity} | Status: {$inc->incident_status} | Type: {$inc->incident_type}";
            if ($inc->summary) {
                $parts[] = '   Summary: '.mb_substr($inc->summary, 0, 300);
            }
            if ($inc->root_cause) {
                $parts[] = '   Root Cause: '.mb_substr($inc->root_cause, 0, 200);
            }
            $categories = collect([
                ...(array) ($inc->root_cause_category ?? []),
                ...(array) ($inc->business_category ?? []),
            ])->filter()->unique()->implode(', ');
            if ($categories) {
                $parts[] = "   Categories: {$categories}";
            }
            if ($inc->fund_loss > 0 || $inc->potential_fund_loss > 0) {
                $parts[] = "   Financial Impact: Loss Rp{$inc->fund_loss}, Potential Rp{$inc->potential_fund_loss}";
            }
            if ($inc->labels->isNotEmpty()) {
                $parts[] = '   Labels: '.$inc->labels->pluck('name')->implode(', ');
            }

            return implode("\n", $parts);
        })->implode("\n\n");
    }

    private function buildAgentCatalog($agents): string
    {
        return $agents->map(function (WarRoomAgentConfig $agent, $i) {
            return ($i + 1).". [{$agent->role_key}] {$agent->display_name} — {$agent->description}";
        })->implode("\n");
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an expert incident response coordinator. Your job is to analyze incident data and select the most relevant specialist agents to investigate the incident from multiple expert perspectives.

Rules:
- Select 3-6 agents that are most relevant to the incidents described
- Always consider at least one technical and one risk/impact perspective
- If financial impact is mentioned, include the tech_risk agent
- If security-related keywords appear (unauthorized, breach, exploit, vulnerability, access), include the security agent
- If database-related keywords appear (query, slow, timeout, replication, deadlock), include the dba agent
- If infrastructure keywords appear (server, network, down, timeout, connection), include the system agent

Return ONLY valid JSON with reasoning:
{"suggested_agents": ["sre", "security"], "reasoning": {"sre": "Infrastructure timeout detected in timeline", "security": "Keywords suggest potential unauthorized access"}}
PROMPT;
    }

    private function buildUserPrompt(string $incidentSummary, string $agentCatalog, ?string $userInstructions): string
    {
        $prompt = "Select the most relevant specialist agents for these incidents:\n\n";
        $prompt .= "## Incidents\n{$incidentSummary}\n\n";
        $prompt .= "## Available Agents\n{$agentCatalog}\n\n";

        if ($userInstructions) {
            $prompt .= "## User Context\n{$userInstructions}\n\n";
        }

        $prompt .= 'Return a JSON object with suggested_agents (array of role_key strings).';

        return $prompt;
    }
}
