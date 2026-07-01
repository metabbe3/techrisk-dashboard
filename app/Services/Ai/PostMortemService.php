<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\Incident;
use App\Services\Markdown\IncidentMarkdownExporter;

class PostMortemService
{
    public function __construct(
        private readonly AiTextService $aiService,
        private readonly IncidentMarkdownExporter $markdownExporter,
    ) {}

    public function generate(Incident $incident): array
    {
        $resolvedModel = AiSetting::get('default_model', config('ai.default_model'));
        $prompt = config('ai.prompts.post_mortem');

        if (! $prompt) {
            return $this->generateFromDataOnly($incident);
        }

        $incident->load(Incident::FULL_RELATIONS)->load('incidentType');

        $incidentMarkdown = $this->markdownExporter->generateCompact($incident);
        $userMessage = "Generate a blameless post-mortem report for the following incident:\n\n{$incidentMarkdown}";

        $defaultResult = [
            'executive_summary' => '',
            'timeline_analysis' => '',
            'root_cause_deep_dive' => '',
            'impact_assessment' => [
                'users_affected' => '',
                'systems_affected' => '',
                'financial_impact' => '',
                'reputation_impact' => '',
            ],
            'lessons_learned' => [],
            'recommendations' => [],
            'severity_assessment' => '',
        ];

        $result = $this->aiService->callAiForJson('post_mortem', $resolvedModel, $prompt['system'], $userMessage, $defaultResult);

        return $this->sanitizeResult($result, $defaultResult);
    }

    public function generateFromDataOnly(Incident $incident): array
    {
        $incident->load(Incident::FULL_RELATIONS)->load('incidentType');

        $summary = $incident->summary ?? 'No summary available.';
        $rootCause = $incident->root_cause ?? 'No root cause analysis available.';
        $timeline = $incident->timeline ?? 'No timeline details available.';
        $remark = $incident->remark ?? '';

        $lessons = [];
        if ($incident->actionImprovements->isNotEmpty()) {
            foreach ($incident->actionImprovements as $action) {
                $lessons[] = "{$action->title}".($action->status ? " [{$action->status}]" : '');
            }
        }

        return [
            'executive_summary' => "Incident {$incident->no}: {$incident->title}\n\n{$summary}",
            'timeline_analysis' => $timeline,
            'root_cause_deep_dive' => $rootCause,
            'impact_assessment' => [
                'users_affected' => 'See incident details.',
                'systems_affected' => 'See incident details.',
                'financial_impact' => $incident->fund_loss
                    ? "Actual loss: Rp {$incident->fund_loss}"
                    : ($incident->potential_fund_loss ? "Potential loss: Rp {$incident->potential_fund_loss}" : 'No financial impact reported.'),
                'reputation_impact' => 'Not assessed.',
            ],
            'lessons_learned' => $lessons,
            'recommendations' => $lessons,
            'severity_assessment' => "Severity: {$incident->severity->value}",
        ];
    }

    private function sanitizeResult(array $result, array $defaultResult): array
    {
        return [
            'executive_summary' => is_string($result['executive_summary'] ?? null) ? trim($result['executive_summary']) : $defaultResult['executive_summary'],
            'timeline_analysis' => is_string($result['timeline_analysis'] ?? null) ? trim($result['timeline_analysis']) : $defaultResult['timeline_analysis'],
            'root_cause_deep_dive' => is_string($result['root_cause_deep_dive'] ?? null) ? trim($result['root_cause_deep_dive']) : $defaultResult['root_cause_deep_dive'],
            'impact_assessment' => is_array($result['impact_assessment'] ?? null) ? [
                'users_affected' => is_string($result['impact_assessment']['users_affected'] ?? null) ? trim($result['impact_assessment']['users_affected']) : '',
                'systems_affected' => is_string($result['impact_assessment']['systems_affected'] ?? null) ? trim($result['impact_assessment']['systems_affected']) : '',
                'financial_impact' => is_string($result['impact_assessment']['financial_impact'] ?? null) ? trim($result['impact_assessment']['financial_impact']) : '',
                'reputation_impact' => is_string($result['impact_assessment']['reputation_impact'] ?? null) ? trim($result['impact_assessment']['reputation_impact']) : '',
            ] : $defaultResult['impact_assessment'],
            'lessons_learned' => collect($result['lessons_learned'] ?? [])
                ->filter(fn ($l) => is_string($l) && filled(trim($l)))
                ->values()
                ->toArray(),
            'recommendations' => collect($result['recommendations'] ?? [])
                ->filter(fn ($r) => is_string($r) && filled(trim($r)))
                ->values()
                ->toArray(),
            'severity_assessment' => is_string($result['severity_assessment'] ?? null) ? trim($result['severity_assessment']) : $defaultResult['severity_assessment'],
        ];
    }
}
