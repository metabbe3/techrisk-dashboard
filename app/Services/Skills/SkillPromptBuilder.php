<?php

namespace App\Services\Skills;

use App\Models\WarRoomAgentConfig;

class SkillPromptBuilder
{
    public function buildSkillPrompt(?WarRoomAgentConfig $agent): string
    {
        if (! $agent) {
            return '';
        }

        $skills = $agent->skillRecords()->where('is_active', true)->orderBy('sort_order')->get();

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
}
