<?php

namespace App\Services\WarRoom;

use App\Models\AiSetting;
use App\Services\Ai\Concerns\InteractsWithAiApi;
use App\Services\Ai\SseStreamingService;
use Illuminate\Support\Facades\Log;

class WarRoomStreamingService
{
    use InteractsWithAiApi;

    public function __construct(
        private SseStreamingService $sseService,
    ) {}

    public function streamCompletion(
        string $model,
        array $messages,
        int $maxTokens,
        array $tools = [],
        ?callable $onDelta = null,
        ?callable $onComplete = null,
    ): array {
        $baseUrl = $this->getBaseUrl();
        $apiKey = $this->getApiKey();
        $timeout = $this->getTimeout();

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'max_completion_tokens' => $maxTokens,
            'stream' => true,
        ];

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        $startTime = microtime(true);
        $previousResponseTimeMs = 0;

        try {
            $result = $this->sseService->stream(
                $baseUrl,
                $apiKey,
                $payload,
                $timeout,
                $onDelta,
            );
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return [
                'content' => '',
                'usage' => [],
                'finish_reason' => 'error',
                'tool_calls' => [],
                'reasoning_content' => null,
                'reasoning_tokens' => null,
                'response_time_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'error' => $e->getMessage(),
                'http_code' => 0,
            ];
        }

        $responseTimeMs = $result['response_time_ms'];
        $previousResponseTimeMs = $responseTimeMs;

        // Extract reasoning tokens
        $reasoningTokens = null;
        if (isset($result['usage']['completion_tokens_details']['reasoning_tokens'])) {
            $reasoningTokens = $result['usage']['completion_tokens_details']['reasoning_tokens'];
        }

        // Final fallback: if streaming returned empty, retry with non-streaming HTTP
        if (blank($result['content']) && empty($result['tool_calls']) && ! $result['error']) {
            Log::warning('[WarRoomStreaming] Empty streaming response, retrying with non-streaming HTTP', [
                'model' => $model,
                'http_code' => $result['http_code'],
            ]);

            return $this->nonStreamingCompletion($model, $messages, $maxTokens, $tools, $onDelta, $previousResponseTimeMs);
        }

        $warRoomResult = [
            'content' => $result['content'],
            'usage' => $result['usage'],
            'finish_reason' => $result['finish_reason'],
            'tool_calls' => $result['tool_calls'],
            'reasoning_content' => $result['reasoning_content'],
            'reasoning_tokens' => $reasoningTokens,
            'response_time_ms' => $responseTimeMs,
            'error' => $result['error'],
            'http_code' => $result['http_code'],
        ];

        if ($onComplete) {
            $onComplete($warRoomResult);
        }

        return $warRoomResult;
    }

    protected function getTimeout(): int
    {
        return (int) AiSetting::get('war_room_agent_timeout',
            config('ai.war_room.agent_timeout', 600));
    }

    private function nonStreamingCompletion(
        string $model,
        array $messages,
        int $maxTokens,
        array $tools,
        ?callable $onDelta,
        int $previousResponseTimeMs,
    ): array {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'max_completion_tokens' => $maxTokens,
        ];

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        $startTime = microtime(true);

        $response = \Illuminate\Support\Facades\Http::withHeaders($this->buildHeaders())
            ->timeout($this->getTimeout())
            ->post($this->buildUrl(), $payload);

        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000) + $previousResponseTimeMs;

        if ($response->failed()) {
            return [
                'content' => '',
                'usage' => [],
                'finish_reason' => 'error',
                'tool_calls' => [],
                'reasoning_content' => null,
                'reasoning_tokens' => null,
                'response_time_ms' => $responseTimeMs,
                'error' => 'AI service error (HTTP '.$response->status().')',
                'http_code' => $response->status(),
            ];
        }

        $responseData = $response->json();
        $responseMessage = $responseData['choices'][0]['message'] ?? [];
        $fullContent = $responseMessage['content'] ?? '';
        $finishReason = $responseData['choices'][0]['finish_reason'] ?? 'stop';
        $usage = $responseData['usage'] ?? [];
        $toolCalls = $responseMessage['tool_calls'] ?? [];

        if ($onDelta && ! blank($fullContent)) {
            $onDelta($fullContent, strlen($fullContent));
        }

        return [
            'content' => $fullContent,
            'usage' => $usage,
            'finish_reason' => $finishReason,
            'tool_calls' => $toolCalls,
            'reasoning_content' => null,
            'reasoning_tokens' => null,
            'response_time_ms' => $responseTimeMs,
            'error' => null,
            'http_code' => $response->status(),
        ];
    }
}
