<?php

namespace App\Services\Skills;

use App\Models\Skill;
use App\Models\WarRoomAgentConfig;
use App\Models\WarRoomSession;

class SkillPromptBuilder
{
    public function buildSkillPrompt(?WarRoomAgentConfig $agent, ?WarRoomSession $session = null): string
    {
        if (! $agent) {
            return '';
        }

        $skills = $this->resolveSkills($agent, $session);

        if ($skills->isEmpty()) {
            return '';
        }

        $parts = [
            "## Assigned Skills & Methodologies\n",
            'You have been assigned the following specialized skills. Apply their frameworks, procedures, and methodologies during your analysis:',
        ];

        foreach ($skills as $skill) {
            $parts[] = '';
            $parts[] = "### {$skill->display_name}";

            if ($skill->frameworks) {
                $parts[] = 'Frameworks: '.implode(', ', $skill->frameworks);
            }

            if ($skill->content) {
                $parts[] = '';
                $parts[] = $skill->content;
            }
        }

        return implode("\n", $parts);
    }

    private function resolveSkills(WarRoomAgentConfig $agent, ?WarRoomSession $session)
    {
        $selectedIds = $session?->getSelectedSkillIdsForAgent($agent->role_key) ?? [];

        if (filled($selectedIds)) {
            return Skill::whereIn('id', $selectedIds)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        }

        return $agent->skillRecords()->where('is_active', true)->orderBy('sort_order')->get();
    }
}
