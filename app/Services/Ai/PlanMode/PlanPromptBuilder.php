<?php

namespace App\Services\Ai\PlanMode;

use App\Models\WarRoomAgentConfig;
use App\Services\Ai\ChatContextService;

class PlanPromptBuilder
{
    public function __construct(
        private ChatContextService $contextService,
    ) {}

    public function buildPlannerSystemPrompt(array $personaKeys): string
    {
        $personaCatalog = $this->buildPersonaCatalog($personaKeys);

        $prompt = config('ai.prompts.plan_mode.system');

        return str_replace('{persona_catalog}', $personaCatalog, $prompt);
    }

    public function buildPlannerUserMessage(string $userMessage, array $history, array $referencedIds, ?array $preAnalysis = null): string
    {
        $parts = [];

        if (! empty($history)) {
            $recentHistory = array_slice($history, -6);
            $historyText = collect($recentHistory)
                ->map(fn ($m) => ucfirst($m['role']).': '.mb_substr($m['content'], 0, 200))
                ->implode("\n");
            $parts[] = "## Conversation History (abbreviated)\n{$historyText}";
        }

        if (! empty($referencedIds)) {
            $incidentContext = $this->buildIncidentSummary($referencedIds);
            if ($incidentContext) {
                $parts[] = "## Referenced Incident Data\n{$incidentContext}";
            } else {
                $parts[] = '## Referenced Incidents: '.implode(', ', $referencedIds);
            }
        }

        $parts[] = "## User's Question\n{$userMessage}";

        if ($preAnalysis) {
            $type = $preAnalysis['question_type'] ?? 'general';
            $domains = implode(', ', $preAnalysis['required_domains'] ?? []);
            $complexity = $preAnalysis['complexity'] ?? 'moderate';
            $aspects = implode(', ', $preAnalysis['key_aspects'] ?? []);
            $approach = $preAnalysis['suggested_approach'] ?? '';
            $reasoning = $preAnalysis['reasoning'] ?? '';

            $min = config('ai.plan_mode.min_subtasks', 2);
            $max = config('ai.plan_mode.max_subtasks', 5);
            $range = "{$min}-{$max}";

            $parts[] = "\n## Pre-Analysis";
            $parts[] = "- Question Type: {$type}";
            $parts[] = "- Required Domains: {$domains}";
            $parts[] = "- Complexity: {$complexity} (suggested range: {$range} subtasks)";
            $parts[] = "- Key Aspects: {$aspects}";
            $parts[] = "- Suggested Approach: {$approach}";
            $parts[] = "- Reasoning: {$reasoning}";

            $parts[] = "\nBased on this analysis, create between {$min} and {$max} subtasks based on how many distinct analytical angles the question truly requires. "
                .'Assign each subtask to the persona whose domain expertise best matches the Required Domains above.';
        }

        $parts[] = "\nCRITICAL MECE RULES (Mutually Exclusive, Collectively Exhaustive):";
        $parts[] = '- Each subtask MUST be independently completable with NO dependency on other subtasks\' outputs';
        $parts[] = '- Each subtask receives the FULL incident context + user question, so scope the task to a specific analytical ANGLE, not a dependent step';
        $parts[] = '- BAD: "Subtask A analyzes root cause, Subtask B builds on A\'s findings to suggest fixes"';
        $parts[] = '- GOOD: "Subtask A: Analyze infrastructure for root cause. Subtask B: Review compliance implications independently"';
        $parts[] = '- Distribute workload evenly — no subtask should be significantly larger in scope than others';
        $parts[] = '- Each subtask description must be self-contained: specify exactly WHAT data to analyze and WHAT to produce';

        $parts[] = "\nDecompose this question into focused analytical subtasks. Return ONLY valid JSON.";

        return implode("\n\n", $parts);
    }

    public function buildSubtaskAgentPrompt(string $description, ?string $personaKey, string $userMessage, array $referencedIds, array $requiredContext = [], ?string $planText = null, int $totalSubtasks = 1, string $outputMode = 'standard'): string
    {
        $parts = [];

        if ($personaKey) {
            $config = WarRoomAgentConfig::findByRole($personaKey);
            if ($config) {
                $parts[] = $config->system_prompt;
                $parts[] = "\n\nYou are responding as **{$config->display_name}**. {$config->description}.";
                if (! empty($config->skills)) {
                    $skillList = collect($config->skills)
                        ->map(fn ($s) => is_array($s) ? ($s['skill'] ?? '') : $s)
                        ->filter(fn ($s) => is_string($s) && filled($s))
                        ->implode(', ');
                    if (filled($skillList)) {
                        $parts[] = "Core capabilities: {$skillList}.";
                    }
                }
            }
        }

        $parts[] = "\n\n## Your Specific Task\n{$description}";
        $parts[] = "\nFocus ONLY on this specific aspect.";

        if ($outputMode === 'caveman' && config('ai.plan_mode.caveman_for_simple', true)) {
            $maxWords = config('ai.plan_mode.caveman_max_words', 200);
            $parts[] = "\n## Output Format — CAVEMAN MODE";
            $parts[] = 'Your output goes to a synthesis agent, NOT the user. Be ultra-concise:';
            $parts[] = '- Fragments only. No complete sentences. No filler words.';
            $parts[] = '- No preamble. No "Based on the data" or "I found that" or "Looking at".';
            $parts[] = '- Data first. Numbers before words.';
            $parts[] = '- Use: "P1: 12. P2: 8. Trend: +15%." NOT: "Looking at the data, there are 12 P1 incidents..."';
            $parts[] = "- Technical accuracy: 100%. Max {$maxWords} words.";
        } else {
            $parts[] = "\n## Required Output Format";
            $parts[] = 'Structure your response with these markdown headers:';
            $parts[] = '### Key Findings — Your 3-5 most important discoveries (cite specific data with incident numbers)';
            $parts[] = '### Evidence — Data points, metrics, and quotes that support each finding';
            $parts[] = '### Recommendations — Specific, actionable next steps within your domain';
            $parts[] = '### Confidence — Rate overall confidence (High/Medium/Low) and state what would increase it';
        }

        if ($planText) {
            $parts[] = "\n## Plan Context";
            $parts[] = "You are one of {$totalSubtasks} specialists collaborating on this plan: \"{$planText}\"";
            $parts[] = 'Your output will be merged with other specialists. Focus on YOUR assigned domain only.';
            $parts[] = 'Avoid repeating background context that other specialists will cover.';
        }

        $parts[] = "\n## Original User Question\n{$userMessage}";

        if (! empty($requiredContext)) {
            $context = $this->contextService->buildTargetedContext($userMessage, $referencedIds, $requiredContext);
        } else {
            $context = $this->contextService->buildSystemPrompt($userMessage, $referencedIds);
            $contextHeaderPos = strpos($context, '--- CURRENT DATA CONTEXT ---');
            if ($contextHeaderPos !== false) {
                $context = substr($context, $contextHeaderPos);
            }
        }
        $parts[] = "\n\n{$context}";

        return implode('', $parts);
    }

    public function buildSynthesisPrompt(string $planText, array $subtaskResults, string $userMessage, ?array $preAnalysis = null): string
    {
        $systemPrompt = config('ai.prompts.plan_synthesis.system');

        $parts = [
            "## Original User Question\n{$userMessage}",
            "\n## Plan\n{$planText}",
        ];

        if ($preAnalysis) {
            $parts[] = "\n## Pre-Analysis";
            $parts[] = '- Question Type: '.($preAnalysis['question_type'] ?? 'general');
            $parts[] = '- Key Aspects: '.implode(', ', $preAnalysis['key_aspects'] ?? []);
            $parts[] = '- Required Domains: '.implode(', ', $preAnalysis['required_domains'] ?? []);
        }

        $parts[] = "\n## Specialist Results";

        foreach ($subtaskResults as $index => $result) {
            $personaLabel = $result['persona_key']
                ? ucfirst(str_replace('-', ' ', $result['persona_key'])).' Analyst'
                : 'General Analyst';
            $isResearch = ($result['is_research'] ?? false);
            $isCaveman = ($result['output_mode'] ?? 'standard') === 'caveman';
            $modeTag = $isCaveman ? ' [CAVEMAN — expand into full sentences]' : '';
            $label = $isResearch ? "{$personaLabel} — Research ".($index + 1) : "{$personaLabel} — Task ".($index + 1).$modeTag;
            $parts[] = "\n### {$label}";
            $parts[] = '**Task**: '.$result['description'];
            $parts[] = "\n{$result['result']}";
        }

        $parts[] = "\n\nCONTRADICTION RESOLUTION:";
        $parts[] = '- When specialists disagree, flag the contradiction explicitly';
        $parts[] = '- Resolution priority: (1) Specific data citations > vague claims, (2) Multiple agreeing specialists > single opinion, (3) Direct measurements > inferred conclusions';
        $parts[] = '- If irreconcilable, present both viewpoints with their supporting evidence';
        $parts[] = '- Never silently override one specialist\'s findings with another\'s';
        $parts[] = '- Quality gate: flag any specialist claim that lacks specific data citations';

        $parts[] = "\n\nSynthesize the above specialist analyses into a single coherent response.";

        return $systemPrompt."\n\n".implode("\n", $parts);
    }

    public function buildClarificationPrompt(string $userMessage, array $history, array $referencedIds): string
    {
        $systemPrompt = config('ai.prompts.plan_clarification.system');

        $parts = [];

        if (! empty($history)) {
            $recentHistory = array_slice($history, -6);
            $historyText = collect($recentHistory)
                ->map(fn ($m) => ucfirst($m['role']).': '.mb_substr($m['content'], 0, 200))
                ->implode("\n");
            $parts[] = "## Conversation History (abbreviated)\n{$historyText}";
        }

        if (! empty($referencedIds)) {
            $parts[] = '## Referenced Incidents: '.implode(', ', $referencedIds);
        }

        $parts[] = "## User's Question\n{$userMessage}";

        return $systemPrompt."\n\n".implode("\n\n", $parts);
    }

    public function buildGapAnalysisPrompt(string $planText, array $subtaskResults, string $userMessage): string
    {
        $systemPrompt = config('ai.prompts.plan_gap_analysis.system');

        $parts = [
            "## Original User Question\n{$userMessage}",
            "\n## Plan\n{$planText}",
            "\n## Specialist Results",
        ];

        foreach ($subtaskResults as $index => $result) {
            $personaLabel = $result['persona_key']
                ? ucfirst(str_replace('-', ' ', $result['persona_key'])).' Analyst'
                : 'General Analyst';
            $parts[] = "\n### {$personaLabel} — Task ".($index + 1);
            $parts[] = '**Task**: '.$result['description'];
            $parts[] = "\n{$result['result']}";
        }

        return $systemPrompt."\n\n".implode("\n", $parts);
    }

    public function buildResearchPrompt(string $topic, string $reason, string $userMessage, array $referencedIds): string
    {
        $systemPrompt = config('ai.prompts.plan_research.system');

        $parts = [
            "## Research Task\n{$topic}",
            "\n## Why This Research Is Needed\n{$reason}",
            "\n## Original User Question\n{$userMessage}",
        ];

        $context = $this->contextService->buildSystemPrompt($userMessage, $referencedIds);
        $contextHeaderPos = strpos($context, '--- CURRENT DATA CONTEXT ---');
        if ($contextHeaderPos !== false) {
            $dataContext = substr($context, $contextHeaderPos);
            $parts[] = "\n\n--- CURRENT DATA CONTEXT ---\n".substr($dataContext, strlen('--- CURRENT DATA CONTEXT ---'));
        }

        return $systemPrompt."\n\n".implode("\n", $parts);
    }

    private function buildPersonaCatalog(array $personaKeys): string
    {
        if (empty($personaKeys)) {
            return 'No personas selected. Assign persona_key as null for all subtasks — a general analyst will handle each task.';
        }

        $catalog = [];
        foreach ($personaKeys as $key) {
            $config = WarRoomAgentConfig::findByRole($key);
            if ($config && $config->role_key !== 'moderator') {
                $entry = "- {$config->role_key}: {$config->display_name} — {$config->description}";

                // Add skills
                if (! empty($config->skills)) {
                    $skillList = collect($config->skills)
                        ->map(fn ($s) => is_array($s) ? ($s['skill'] ?? '') : $s)
                        ->filter(fn ($s) => is_string($s) && filled($s))
                        ->implode(', ');
                    if (filled($skillList)) {
                        $entry .= "\n  Skills: {$skillList}";
                    }
                }

                // Add domain tags extracted from description + skills
                $text = strtolower(($config->description ?? '').' '.implode(' ', $config->skills ?? []));
                $domainKeywords = [
                    'infrastructure' => ['infrastructure', 'server', 'network', 'cloud', 'sre', 'reliability', 'availability', 'sla'],
                    'database' => ['database', 'query', 'data integrity', 'replication', 'sql', 'dba'],
                    'security' => ['security', 'threat', 'vulnerability', 'forensic', 'penetration', 'compliance'],
                    'compliance' => ['compliance', 'regulatory', 'audit', 'policy', 'governance'],
                    'frontend' => ['frontend', 'ui', 'ux', 'client-side', 'react', 'design', 'user experience'],
                    'backend' => ['backend', 'api', 'code', 'service', 'microservice', 'server-side'],
                    'quality' => ['quality', 'testing', 'qa', 'regression', 'test'],
                    'risk' => ['risk', 'financial', 'impact', 'scoring', 'regulatory', 'reputation'],
                    'support' => ['support', 'user-facing', 'ticket', 'customer'],
                    'project' => ['project', 'timeline', 'resource', 'stakeholder', 'pm'],
                    'data' => ['data', 'analytics', 'pattern', 'anomaly', 'metrics', 'statistic'],
                ];

                $domains = [];
                foreach ($domainKeywords as $domain => $keywords) {
                    foreach ($keywords as $keyword) {
                        if (str_contains($text, $keyword)) {
                            $domains[] = $domain;
                            break;
                        }
                    }
                }

                if (! empty($domains)) {
                    $uniqueDomains = array_unique($domains);
                    $entry .= "\n  Domains: ".implode(', ', $uniqueDomains);
                }

                $catalog[] = $entry;
            }
        }

        if (empty($catalog)) {
            return 'No valid personas found. Assign persona_key as null for all subtasks.';
        }

        return implode("\n", $catalog);
    }

    private function buildIncidentSummary(array $referencedIds): string
    {
        $incidents = \App\Models\Incident::whereIn('no', $referencedIds)
            ->with(\App\Models\Incident::FULL_RELATIONS)
            ->get();

        if ($incidents->isEmpty()) {
            return '';
        }

        $exporter = app(\App\Services\Markdown\IncidentMarkdownExporter::class);
        $parts = [];

        foreach ($incidents->take(3) as $incident) {
            $md = $exporter->generateForContext($incident);
            // Truncate to ~2000 chars per incident to avoid overwhelming the planner
            if (strlen($md) > 2000) {
                $md = mb_substr($md, 0, 2000)."\n... (truncated)";
            }
            $parts[] = $md;
        }

        return implode("\n\n---\n\n", $parts);
    }
}
