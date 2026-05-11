<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\AiUsageLog;
use App\Models\WarRoomAgentConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AiChatService
{
    public function __construct(
        private ChatContextService $contextService,
    ) {}

    public function chat(array $messages, string $userMessage, ?string $model = null, bool $logUsage = true, array $referencedIds = []): AiTextResult
    {
        $resolvedModel = $model ?? AiSetting::get('default_model', config('ai.default_model'));

        $userId = auth()->id() ?? 'guest';
        if (! RateLimiter::attempt("ai-chat:{$userId}", 6, fn () => true)) {
            return AiTextResult::failure('Rate limit exceeded. Please wait a moment before sending another message.');
        }

        $systemPrompt = $this->contextService->buildSystemPrompt($userMessage, $referencedIds);
        $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];

        $maxHistory = config('ai.chat_max_history', 20);
        $historyMessages = array_slice($messages, -$maxHistory);
        foreach ($historyMessages as $msg) {
            $apiMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        // Replace the last user message with the enriched version
        // (may include slash command transforms + web search results)
        for ($i = count($apiMessages) - 1; $i >= 1; $i--) {
            if (($apiMessages[$i]['role'] ?? '') === 'user') {
                $apiMessages[$i]['content'] = $userMessage;
                break;
            }
        }

        $startTime = microtime(true);
        $result = null;

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout($this->getTimeout())
                ->post($this->buildUrl(), [
                    'model' => $resolvedModel,
                    'messages' => $apiMessages,
                    'max_tokens' => config('ai.chat_max_tokens', 4000),
                ]);

            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            if ($response->status() === 429) {
                $result = AiTextResult::failure('Rate limit exceeded. Please wait a moment.', $resolvedModel, $responseTimeMs);
            } elseif ($response->status() === 401) {
                $result = AiTextResult::failure('Authentication failed. Check your API key in AI settings.', $resolvedModel, $responseTimeMs);
            } elseif ($response->failed()) {
                Log::warning('AI chat service error', ['status' => $response->status(), 'body' => $response->body()]);
                $result = AiTextResult::failure('AI service error (HTTP '.$response->status().'). Please try again.', $resolvedModel, $responseTimeMs);
            } else {
                $content = $responseData['choices'][0]['message']['content'] ?? '';
                $finishReason = $responseData['choices'][0]['finish_reason'] ?? '';

                if (blank($content)) {
                    Log::warning('AI returned empty content', [
                        'finish_reason' => $finishReason,
                        'usage' => $usage,
                        'model' => $resolvedModel,
                    ]);
                    $result = AiTextResult::failure(
                        'AI returned an empty response. The context may be too large — try referencing fewer incidents.',
                        $resolvedModel,
                        $responseTimeMs
                    );
                } else {
                    $result = AiTextResult::success(
                        text: $content,
                        model: $resolvedModel,
                        promptTokens: $usage['prompt_tokens'] ?? null,
                        completionTokens: $usage['completion_tokens'] ?? null,
                        totalTokens: $usage['total_tokens'] ?? null,
                        responseTimeMs: $responseTimeMs,
                        apiRequestId: $responseData['id'] ?? null,
                    );
                }
            }
        } catch (ConnectionException $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            $result = AiTextResult::failure('Cannot connect to AI service. Please check your network and try again.', $resolvedModel, $responseTimeMs);
        } catch (\Throwable $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            Log::warning('AI chat unexpected error', ['error' => $e->getMessage()]);
            $result = AiTextResult::failure('Unexpected error: '.$e->getMessage(), $resolvedModel, $responseTimeMs);
        }

        if ($logUsage) {
            $this->logUsage($resolvedModel, $result, strlen($userMessage));
        }

        return $result;
    }

    public function chatWithPersona(array $messages, string $userMessage, ?string $model, WarRoomAgentConfig $persona, array $referencedIds = []): AiTextResult
    {
        $resolvedModel = $persona->model_override ?? $model ?? AiSetting::get('default_model', config('ai.default_model'));

        $userId = auth()->id() ?? 'guest';
        if (! RateLimiter::attempt("ai-chat-persona:{$userId}", 20, fn () => true)) {
            return AiTextResult::failure('Rate limit exceeded. Please wait a moment before sending another message.');
        }

        $systemPrompt = $this->contextService->buildPersonaSystemPrompt($persona, $userMessage, $referencedIds);
        $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];

        $maxHistory = config('ai.chat_max_history', 20);
        $historyMessages = array_slice($messages, -$maxHistory);
        foreach ($historyMessages as $msg) {
            $apiMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        for ($i = count($apiMessages) - 1; $i >= 1; $i--) {
            if (($apiMessages[$i]['role'] ?? '') === 'user') {
                $apiMessages[$i]['content'] = $userMessage;
                break;
            }
        }

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout($this->getTimeout())
                ->post($this->buildUrl(), [
                    'model' => $resolvedModel,
                    'messages' => $apiMessages,
                    'max_tokens' => config('ai.chat_max_tokens', 4000),
                ]);

            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            if ($response->status() === 429) {
                return AiTextResult::failure('Rate limit exceeded. Please wait a moment.', $resolvedModel, $responseTimeMs);
            }

            if ($response->status() === 401) {
                return AiTextResult::failure('Authentication failed. Check your API key.', $resolvedModel, $responseTimeMs);
            }

            if ($response->failed()) {
                Log::warning('AI persona chat error', ['status' => $response->status(), 'persona' => $persona->role_key]);

                return AiTextResult::failure('AI service error (HTTP '.$response->status().'). Please try again.', $resolvedModel, $responseTimeMs);
            }

            $content = $responseData['choices'][0]['message']['content'] ?? '';
            $finishReason = $responseData['choices'][0]['finish_reason'] ?? '';

            if (blank($content)) {
                return AiTextResult::failure('AI returned an empty response. Try rephrasing your question.', $resolvedModel, $responseTimeMs);
            }

            return AiTextResult::success(
                text: $content,
                model: $resolvedModel,
                promptTokens: $usage['prompt_tokens'] ?? null,
                completionTokens: $usage['completion_tokens'] ?? null,
                totalTokens: $usage['total_tokens'] ?? null,
                responseTimeMs: $responseTimeMs,
                apiRequestId: $responseData['id'] ?? null,
            );
        } catch (ConnectionException $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;

            return AiTextResult::failure('Cannot connect to AI service. Please check your network.', $resolvedModel, $responseTimeMs);
        } catch (\Throwable $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            Log::warning('AI persona chat unexpected error', ['error' => $e->getMessage()]);

            return AiTextResult::failure('Unexpected error: '.$e->getMessage(), $resolvedModel, $responseTimeMs);
        }
    }

    private function buildHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->getApiKey(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    private function buildUrl(): string
    {
        return rtrim($this->getBaseUrl(), '/').'/chat/completions';
    }

    private function getBaseUrl(): string
    {
        return AiSetting::get('base_url', config('ai.base_url', ''));
    }

    private function getApiKey(): string
    {
        return AiSetting::get('api_key', config('ai.api_key', ''));
    }

    private function getTimeout(): int
    {
        return (int) AiSetting::get('timeout', config('ai.timeout', 60));
    }

    private function logUsage(string $model, AiTextResult $result, int $inputLength): void
    {
        try {
            AiUsageLog::create([
                'user_id' => auth()->id(),
                'user_email' => auth()->user()?->email,
                'field_type' => 'chat_assistant',
                'model' => $model,
                'input_length' => $inputLength,
                'output_length' => $result->success ? strlen($result->text ?? '') : null,
                'prompt_tokens' => $result->promptTokens,
                'completion_tokens' => $result->completionTokens,
                'total_tokens' => $result->totalTokens,
                'response_time_ms' => $result->responseTimeMs,
                'api_request_id' => $result->apiRequestId,
                'success' => $result->success,
                'error_message' => $result->success ? null : $result->error,
                'requested_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log AI chat usage', ['error' => $e->getMessage()]);
        }
    }

    public function logChatUsage(string $model, AiTextResult $result, int $inputLength, string $messageId): void
    {
        try {
            AiUsageLog::create([
                'user_id' => auth()->id(),
                'user_email' => auth()->user()?->email,
                'field_type' => 'chat_assistant',
                'model' => $model,
                'input_length' => $inputLength,
                'output_length' => $result->success ? strlen($result->text ?? '') : null,
                'prompt_tokens' => $result->promptTokens,
                'completion_tokens' => $result->completionTokens,
                'total_tokens' => $result->totalTokens,
                'response_time_ms' => $result->responseTimeMs,
                'api_request_id' => $result->apiRequestId,
                'success' => $result->success,
                'error_message' => $result->success ? null : $result->error,
                'metadata' => ['message_id' => $messageId],
                'requested_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log AI chat usage', ['error' => $e->getMessage()]);
        }
    }

    public function getApiBaseUrl(): string
    {
        return $this->getBaseUrl();
    }

    public function getApiApiKey(): string
    {
        return $this->getApiKey();
    }
}
