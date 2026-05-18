<?php

namespace App\Jobs\Ai;

use App\Models\AiSetting;
use App\Models\Incident;
use App\Models\ProactiveInsight;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
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

    public function handle(): void
    {
        $incident = Incident::with(['pic', 'labels', 'actionImprovements'])->find($this->incidentId);

        if (! $incident) {
            return;
        }

        $model = config('ai.perception.proactive_analysis_model', 'FAST-MODEL');

        $prompt = $this->buildPrompt($incident);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.AiSetting::get('api_key', config('ai.api_key', '')),
                'Content-Type' => 'application/json',
            ])
                ->timeout(30)
                ->post(rtrim(AiSetting::get('base_url', config('ai.base_url', '')), '/').'/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a technical risk analyst. Provide a brief, actionable assessment of this incident. Focus on: key risks, recommended immediate actions, and potential similar patterns. Be concise (2-3 paragraphs max).'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 500,
                ]);

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';

            if (blank($content)) {
                return;
            }

            ProactiveInsight::create([
                'incident_id' => $incident->id,
                'user_id' => null,
                'insight_type' => $this->insightType,
                'content' => $content,
                'is_read' => false,
                'created_at' => now(),
            ]);

            Log::info('[Perception] Created proactive insight', [
                'incident_id' => $incident->id,
                'type' => $this->insightType,
            ]);

        } catch (\Throwable $e) {
            Log::warning('[Perception] Failed to analyze incident', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildPrompt(Incident $incident): string
    {
        $parts = [
            "Incident: {$incident->no} - {$incident->title}",
            "Severity: {$incident->severity} | Status: {$incident->incident_status}",
            "Date: {$incident->incident_date?->format('Y-m-d')}",
        ];

        if ($incident->summary) {
            $parts[] = "Summary: {$incident->summary}";
        }
        if ($incident->root_cause) {
            $parts[] = 'Root Cause: '.str($incident->root_cause)->limit(500);
        }
        if ($incident->fund_loss > 0) {
            $parts[] = 'Fund Loss: Rp '.number_format($incident->fund_loss);
        }
        if ($incident->pic) {
            $parts[] = "PIC: {$incident->pic->name}";
        }

        return implode("\n", $parts);
    }
}
