<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\WarRoomAgentConfig;
use App\Services\Ai\Concerns\InteractsWithAiApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AiChatService
{
    use InteractsWithAiApi;

    public function __construct(
        private ChatContextService $contextService,
        private AiUsageLogger $usageLogger,
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

            $responseTimeMs = $this->elapsedMs($startTime);
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            $errorResult = AiResponseHandler::checkErrors($response, $resolvedModel, $startTime);
            if ($errorResult) {
                $result = $errorResult;
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
            $responseTimeMs = $this->elapsedMs($startTime);
            $result = AiTextResult::failure('Cannot connect to AI service. Please check your network and try again.', $resolvedModel, $responseTimeMs);
        } catch (\Throwable $e) {
            $responseTimeMs = $this->elapsedMs($startTime);
            Log::warning('AI chat unexpected error', ['error' => $e->getMessage()]);
            $result = AiTextResult::failure('Unexpected error: '.$e->getMessage(), $resolvedModel, $responseTimeMs);
        }

        if ($logUsage) {
            $this->usageLogger->logFromResult('chat_assistant', $resolvedModel, $result, strlen($userMessage));
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

            $responseTimeMs = $this->elapsedMs($startTime);
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            $errorResult = AiResponseHandler::checkErrors($response, $resolvedModel, $startTime);
            if ($errorResult) {
                return $errorResult;
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
            $responseTimeMs = $this->elapsedMs($startTime);

            return AiTextResult::failure('Cannot connect to AI service. Please check your network.', $resolvedModel, $responseTimeMs);
        } catch (\Throwable $e) {
            $responseTimeMs = $this->elapsedMs($startTime);
            Log::warning('AI persona chat unexpected error', ['error' => $e->getMessage()]);

            return AiTextResult::failure('Unexpected error: '.$e->getMessage(), $resolvedModel, $responseTimeMs);
        }
    }

    public function logChatUsage(string $model, AiTextResult $result, int $inputLength, string $messageId): void
    {
        $this->usageLogger->logFromResult(
            fieldType: 'chat_assistant',
            model: $model,
            result: $result,
            inputLength: $inputLength,
            metadata: ['message_id' => $messageId],
        );
    }

    /**
     * Generate a short conversation title from the first user message and AI response.
     * Uses the FAST-MODEL for low-latency inference.
     */
    public function generateTitle(string $firstMessage, ?string $aiResponse = null): ?string
    {
        try {
            $userContent = $firstMessage;
            if ($aiResponse) {
                $userContent .= "\n\nAI Response (first 500 chars):\n".mb_substr($aiResponse, 0, 500);
            }

            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(10)
                ->post($this->buildUrl(), [
                    'model' => 'FAST-MODEL',
                    'messages' => [
                        ['role' => 'system', 'content' => config('ai.chat_title_prompt')],
                        ['role' => 'user', 'content' => $userContent],
                    ],
                    'max_tokens' => 30,
                ]);

            if ($response->successful()) {
                $title = trim($response->json('choices.0.message.content', ''));
                if ($title && strlen($title) <= 80) {
                    return $title;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to generate chat title', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
