<?php

namespace App\Services\Skills;

use App\Models\WarRoomAgentConfig;
use App\Models\WarRoomSession;
use App\Services\Ai\AiTextService;

class SkillRoutingService
{
    public function __construct(
        private AiTextService $ai,
    ) {}

    public function selectSkillsForSession(WarRoomSession $session): void
    {
        if (! config('ai.war_room.skill_routing.enabled', true)) {
            return;
        }

        $routing = [];

        foreach ($session->getAgentRoles() as $role) {
            $config = WarRoomAgentConfig::findByRole($role);

            if (! $config) {
                continue;
            }

            $skills = $config->skillRecords()->where('is_active', true)->orderBy('sort_order')->get();
            $minToTrigger = config('ai.war_room.skill_routing.min_skills_to_trigger', 4);

            if ($skills->count() <= $minToTrigger) {
                $routing[$role] = $skills->pluck('id')->toArray();

                continue;
            }

            $selectedIds = $this->selectForAgent($config, $skills, $session);

            if (empty($selectedIds)) {
                $routing[$role] = $skills->pluck('id')->toArray();

                continue;
            }

            $validIds = $skills->pluck('id')->toArray();
            $filtered = array_values(array_filter($selectedIds, fn ($id) => in_array($id, $validIds)));

            $routing[$role] = count($filtered) >= 3 ? $filtered : $validIds;
        }

        $session->update(['selected_skills' => $routing]);
    }

    private function selectForAgent(WarRoomAgentConfig $config, $skills, WarRoomSession $session): array
    {
        $incidentContext = is_string($session->incident_context)
            ? $session->incident_context
            : implode("\n", $session->incident_context ?? []);

        $truncated = $this->truncateContext($incidentContext);

        [$system, $user] = $this->buildSelectionPrompt($config, $skills, $truncated);

        $model = config('ai.war_room.skill_routing.model', 'FAST-MODEL');

        $result = $this->ai->callAiForJson(
            'skill_routing',
            $model,
            $system,
            $user,
            ['selected_skill_ids' => []]
        );

        return $result['selected_skill_ids'] ?? [];
    }

    public function buildSelectionPrompt(WarRoomAgentConfig $config, $skills, string $incidentContext): array
    {
        $maxSkills = config('ai.war_room.skill_routing.max_skills_per_agent', 5);

        $system = config('ai.prompts.skill_routing.system');

        $catalog = $skills->map(fn ($skill, $i) => sprintf(
            '%d. [%s] - "%s" - Frameworks: %s',
            $i + 1,
            $skill->id,
            $skill->display_name,
            implode(', ', $skill->frameworks ?? ['none'])
        ))->implode("\n");

        $user = <<<PROMPT
Agent Role: {$config->display_name}
Max skills to select: {$maxSkills}

Incident Summary:
{$incidentContext}

Available Skills:
{$catalog}
PROMPT;

        return [$system, $user];
    }

    public function truncateContext(string $context, int $maxChars = 1500): string
    {
        $context = strip_tags($context);

        if (strlen($context) <= $maxChars) {
            return $context;
        }

        $truncated = substr($context, 0, $maxChars);
        $lastNewline = strrpos($truncated, "\n");

        if ($lastNewline !== false) {
            $truncated = substr($truncated, 0, $lastNewline);
        }

        return trim($truncated)."\n\n[...truncated]";
    }
}
