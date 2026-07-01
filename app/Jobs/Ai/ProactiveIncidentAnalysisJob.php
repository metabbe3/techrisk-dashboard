<?php

namespace App\Jobs\Ai;

use App\Models\Incident;
use App\Models\ProactiveInsight;
use App\Services\Ai\AiTextService;
use App\Services\Ai\AiUsageLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProactiveIncidentAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public int $incidentId,
        public string $insightType,
    ) {
        $this->onQueue('default');
    }

    public function handle(AiTextService $textService, AiUsageLogger $usageLogger): void
    {
        $incident = Incident::with(['pic', 'labels', 'actionImprovements'])->find($this->incidentId);

        if (! $incident) {
            return;
        }

        $model = config('ai.perception.proactive_analysis_model', 'FAST-MODEL');
        $prompt = config('ai.prompts.proactive_analysis.system', '');
        $userMessage = $this->buildPrompt($incident);
        $startTime = microtime(true);

        try {
            $result = $textService->callAiForJson(
                'proactive_incident_analysis',
                $model,
                $prompt,
                $userMessage,
                ['risk_level' => 'low', 'key_risks' => [], 'recommended_actions' => [], 'similar_patterns' => '', 'escalation_needed' => false],
                maxTokens: 500,
            );

            $responseTimeMs = (microtime(true) - $startTime) * 1000;

            if (empty($result) || ! isset($result['risk_level'])) {
                Log::warning('[Perception] Empty or invalid proactive analysis response', [
                    'incident_id' => $this->incidentId,
                ]);

                return;
            }

            ProactiveInsight::create([
                'incident_id' => $incident->id,
                'user_id' => null,
                'insight_type' => $this->insightType,
                'content' => json_encode($result),
                'is_read' => false,
                'created_at' => now(),
            ]);

            Log::info('[Perception] Created proactive insight', [
                'incident_id' => $incident->id,
                'type' => $this->insightType,
            ]);

        } catch (\Throwable $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            Log::warning('[Perception] Failed to analyze incident', [
                'incident_id' => $this->incidentId,
                'error' => $e->getMessage(),
            ]);

            $usageLogger->log(
                fieldType: 'proactive_incident_analysis',
                model: $model,
                success: false,
                responseTimeMs: $responseTimeMs,
                errorMessage: $e->getMessage(),
                metadata: ['incident_id' => $this->incidentId, 'insight_type' => $this->insightType],
            );
        }
    }

    private function buildPrompt(Incident $incident): string
    {
        $parts = [
            "Incident: {$incident->no} - {$incident->title}",
            "Severity: {$incident->severity->value} | Status: {$incident->incident_status->value} | Type: {$incident->incident_type}",
            "Date: {$incident->incident_date?->format('Y-m-d')}",
        ];

        if ($incident->summary) {
            $parts[] = "Summary: {$incident->summary}";
        }
        if ($incident->root_cause) {
            $parts[] = 'Root Cause: '.str($incident->root_cause)->limit(500);
        }
        if (! empty($incident->root_cause_category)) {
            $parts[] = 'Root Cause Categories: '.implode(', ', $incident->root_cause_category);
        }
        if (! empty($incident->responsible_team)) {
            $parts[] = 'Responsible Team: '.implode(', ', $incident->responsible_team);
        }
        if ($incident->fund_loss > 0) {
            $parts[] = 'Fund Loss: Rp '.number_format($incident->fund_loss);
        }
        if ($incident->potential_fund_loss > 0) {
            $parts[] = 'Potential Fund Loss: Rp '.number_format($incident->potential_fund_loss);
        }
        if ($incident->pic) {
            $parts[] = "PIC: {$incident->pic->name}";
        }
        $labels = $incident->labels->pluck('name')->implode(', ');
        if ($labels) {
            $parts[] = "Labels: {$labels}";
        }

        return implode("\n", $parts);
    }
}
