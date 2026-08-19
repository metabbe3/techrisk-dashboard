<?php

namespace App\Jobs\WarRoom;

use App\Events\WarRoomPreAnalysisCompleted;
use App\Models\WarRoomSession;
use App\Services\Ai\Concerns\InteractsWithAiApi;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RunPreAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use InteractsWithAiApi;

    public const JOB_TIMEOUT = 120;

    public $tries = 2;

    public $timeout = self::JOB_TIMEOUT;

    public function __construct(
        public WarRoomSession $session
    ) {
        $this->onQueue('war-room');
    }

    public function handle(WarRoomService $warRoomService): void
    {
        try {
            $session = $this->session->fresh();
            $session->loadMissing('incidents');

            $incidentContext = is_string($session->incident_context)
                ? $session->incident_context
                : implode("\n", $session->incident_context ?? []);

            $systemPrompt = config('ai.prompts.war_room_pre_analysis.system');
            $model = app(\App\Services\Ai\ModelRouter::class)->pick('reasoning', $session->model ?? config('ai.default_model'));

            // Post to the shared /chat/completions endpoint (buildUrl) like every other
            // AI call — the old code hit the bare base URL, which can hang on providers
            // whose root path never responds. Add a connect timeout so a dead socket
            // fails fast: a call that blocks without throwing never reaches the catch /
            // failed() round-1 dispatch, stranding the session at current_round=0.
            $response = Http::withHeaders($this->buildHeaders())
                ->withOptions([
                    'connect_timeout' => (float) config('ai.war_room.pre_analysis_connect_timeout', 15),
                    'timeout' => (float) config('ai.war_room.pre_analysis_timeout', 90),
                ])
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => "Analyze the following incident data and provide structured pre-analysis:\n\n{$incidentContext}"],
                    ],
                    'max_tokens' => 4096,
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Pre-analysis API call failed: '.$response->status());
            }

            $content = $response->json('choices.0.message.content', '');

            $preAnalysis = $this->parseResponse($content);

            $session->update(['pre_analysis' => $preAnalysis]);

            $tokens = $response->json('usage.total_tokens', 0);
            if ($tokens > 0) {
                $session->addTokens($tokens);
            }

            broadcast(new WarRoomPreAnalysisCompleted($session, $preAnalysis));

            Log::info('[WarRoom] Pre-analysis completed', [
                'session_id' => $session->id,
                'tokens' => $tokens,
            ]);

            $warRoomService->dispatchRound($session, 1);

        } catch (\Throwable $e) {
            Log::warning('[WarRoom] Pre-analysis failed, proceeding without it', [
                'session_id' => $this->session->id,
                'error' => $e->getMessage(),
            ]);

            // Graceful degradation: still dispatch agents without pre-analysis
            try {
                $warRoomService->dispatchRound($this->session->fresh(), 1);
            } catch (\Throwable $dispatchError) {
                Log::error('[WarRoom] Failed to dispatch round after pre-analysis failure', [
                    'session_id' => $this->session->id,
                    'error' => $dispatchError->getMessage(),
                ]);
                $this->session->fresh()->markFailed('Failed after pre-analysis: '.$dispatchError->getMessage());
            }
        }
    }

    private function parseResponse(string $content): array
    {
        // Strip markdown code fences if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode(trim($content), true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // If JSON parsing fails, wrap the raw text
        return [
            'reasoning' => trim($content),
            'key_concerns' => [],
            'hypotheses' => [],
            'data_gaps' => [],
            'domain_focus' => [],
            'cross_domain_alerts' => [],
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[WarRoom] Pre-analysis job failed', [
            'session_id' => $this->session->id,
            'error' => $exception->getMessage(),
        ]);

        // Still try to dispatch agents
        try {
            app(WarRoomService::class)->dispatchRound($this->session->fresh(), 1);
        } catch (\Throwable $e) {
            $this->session->fresh()->markFailed('Pre-analysis failed: '.$e->getMessage());
        }
    }
}
