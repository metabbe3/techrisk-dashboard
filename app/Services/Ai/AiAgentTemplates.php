<?php

namespace App\Services\Ai;

/**
 * Starter templates for the agent create form. Pure data — picking one just
 * pre-fills the form; everything stays editable before save.
 */
class AiAgentTemplates
{
    /**
     * @return array<string, array{name: string, description: string, instructions: string, include_context: bool}>
     */
    public static function all(): array
    {
        return [
            'Daily Incident Digest' => [
                'name' => 'Daily Incident Digest',
                'description' => 'Morning summary of the incident landscape',
                'instructions' => "You are a technical-risk operations assistant. Using the dashboard context provided, produce a short morning digest:\n1) Open incidents by severity\n2) The three most urgent items with a one-line reason each\n3) Any fund-loss exposure worth flagging\n\nKeep it under 200 words, plain text.",
                'include_context' => true,
            ],
            'Weekly Trend Watch' => [
                'name' => 'Weekly Trend Watch',
                'description' => 'Weekly risk trend summary with one recommendation',
                'instructions' => 'You are a technical-risk analyst. Using the dashboard context provided, write a one-paragraph trend summary: dominant incident categories, whether severity is trending up or down, and one concrete recommendation for the week ahead.',
                'include_context' => true,
            ],
            'Open-Case Escalation Scan' => [
                'name' => 'Open-Case Escalation Scan',
                'description' => 'Flags aging open cases that may need escalation',
                'instructions' => 'You are a technical-risk reviewer. Based on the dashboard context, identify signals that open cases may be aging or stalling (e.g. high open counts vs completed, high-value fund loss still unsettled). List up to five escalation candidates with the reason. If nothing looks at risk, say so explicitly.',
                'include_context' => true,
            ],
            'Postmortem Draft Assistant' => [
                'name' => 'Postmortem Draft Assistant',
                'description' => 'Drafts a postmortem skeleton from current data',
                'instructions' => 'You are a technical-risk report writer. Using the dashboard context provided, draft a postmortem skeleton with these sections: Summary, Impact, Contributing Factors, Action Items, Lessons Learned. Mark every section with [REVIEW] placeholders where human input is required.',
                'include_context' => true,
            ],
        ];
    }
}
