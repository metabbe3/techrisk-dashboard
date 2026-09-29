<?php

namespace App\Services\Ai;

use App\Models\AiSetting;

/**
 * Turns a one-sentence natural-language description ("every morning at 8:30
 * summarize critical incidents and email me") into a complete AiAgent form
 * draft: name, description, instructions, include_context, and a schedule
 * preset. Pure drafting — the user reviews/edits everything before saving.
 */
class AiAgentDraftService
{
    public function __construct(private AiTextService $ai) {}

    /**
     * @param  array<string, string>  $agentOptions  existing enabled agents as id => name, referenced by the model for runs_after/reports_to
     * @return array{success: bool, agent: array{name: string, description: string, instructions: string, include_context: bool, expected_output: string, require_json: bool, schedule_type: string, run_time: string, run_weekday: int, depends_on_agent_id: ?string, reports_to_agent_id: ?string, include_memory: bool}, error: ?string}
     */
    public function draft(string $userPrompt, ?string $model = null, array $agentOptions = []): array
    {
        $empty = [
            'name' => '',
            'description' => '',
            'instructions' => '',
            'include_context' => true,
            'expected_output' => '',
            'require_json' => false,
            'schedule_type' => 'manual',
            'run_time' => '09:00',
            'run_weekday' => 1,
            'depends_on_agent_id' => null,
            'reports_to_agent_id' => null,
            'include_memory' => false,
        ];

        $systemPrompt = (string) config('ai.prompts.agent_draft.system');
        if ($systemPrompt === '') {
            return ['success' => false, 'agent' => $empty, 'error' => 'Agent drafting is not configured.'];
        }

        $userPrompt = trim($userPrompt);
        if ($userPrompt === '') {
            return ['success' => false, 'agent' => $empty, 'error' => 'Describe what the agent should do first.'];
        }

        if ($agentOptions !== []) {
            $userPrompt .= "\n\nExisting agents (use the exact id when linking):\n"
                .collect($agentOptions)->map(fn (string $name, string $id) => "- {$id}: {$name}")->implode("\n");
        }

        $resolvedModel = $model ?? AiSetting::get('default_model', config('ai.default_model'));

        // ponytail: callAiForJson has no failure flag — it returns $defaultResult
        // verbatim on failure, so null name is the "nothing usable" sentinel.
        $result = $this->ai->callAiForJson('agent_draft', $resolvedModel, $systemPrompt, $userPrompt, ['name' => null]);

        $name = trim((string) ($result['name'] ?? ''));
        $instructions = trim((string) ($result['instructions'] ?? ''));

        if ($name === '' || $instructions === '') {
            return ['success' => false, 'agent' => $empty, 'error' => 'AI could not produce a usable draft. Try rephrasing.'];
        }

        // Only ids that actually exist pass through — hallucinated names/ids drop to null.
        $dependsOn = $this->resolveAgentId($result['runs_after'] ?? null, $agentOptions);
        $reportsTo = $this->resolveAgentId($result['reports_to'] ?? null, $agentOptions);
        $linked = $dependsOn !== null || $reportsTo !== null;

        return [
            'success' => true,
            'agent' => [
                'name' => mb_substr($name, 0, 120),
                'description' => mb_substr(trim((string) ($result['description'] ?? '')), 0, 255),
                'instructions' => mb_substr($instructions, 0, 20000),
                'include_context' => filter_var($result['include_context'] ?? true, FILTER_VALIDATE_BOOL),
                'expected_output' => mb_substr(trim((string) ($result['expected_output'] ?? '')), 0, 2000),
                'require_json' => filter_var($result['require_json'] ?? false, FILTER_VALIDATE_BOOL),
                'schedule_type' => $this->normalizeScheduleType((string) ($result['schedule_type'] ?? '')),
                'run_time' => $this->normalizeTime((string) ($result['run_time'] ?? '')),
                'run_weekday' => $this->normalizeWeekday($result['run_weekday'] ?? 1),
                'depends_on_agent_id' => $dependsOn,
                'reports_to_agent_id' => $reportsTo,
                'include_memory' => filter_var($result['include_memory'] ?? $linked, FILTER_VALIDATE_BOOL),
            ],
            'error' => null,
        ];
    }

    private function resolveAgentId(mixed $value, array $agentOptions): ?string
    {
        $id = trim((string) $value);

        return $id !== '' && isset($agentOptions[$id]) ? $id : null;
    }

    private function normalizeScheduleType(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['manual', 'daily', 'hourly', 'weekly', 'custom'], true) ? $value : 'manual';
    }

    private function normalizeTime(string $value): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return '09:00';
    }

    private function normalizeWeekday(mixed $value): int
    {
        $weekday = (int) $value;

        return $weekday >= 1 && $weekday <= 7 ? $weekday : 1;
    }
}
