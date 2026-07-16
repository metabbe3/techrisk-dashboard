<?php

namespace App\Services\Ai;

use App\Services\Ai\Concerns\InteractsWithAiApi;
use App\Services\Ai\Concerns\NormalizesUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AgenticChatService
{
    use InteractsWithAiApi;
    use NormalizesUsage;

    public function __construct(
        private ToolRegistryService $toolRegistry,
    ) {}

    public function chatWithTools(
        array $messages,
        string $userMessage,
        ?string $model = null,
        array $referencedIds = [],
        int $maxToolRounds = 3,
    ): AgenticChatResult {
        $resolvedModel = $model ?? AiSetting::get('default_model', config('ai.default_model'));
        $maxToolRounds = $maxToolRounds ?: (int) config('ai.tools.chat_max_rounds', 3);
        $maxTokens = config('ai.chat_max_tokens', 4000);

        $userId = auth()->id() ?? 'guest';
        if (! RateLimiter::attempt("ai-chat-tools:{$userId}", 6, fn () => true)) {
            return AgenticChatResult::failure('Rate limit exceeded. Please wait a moment.');
        }

        $startTime = microtime(true);
        $fullContent = '';
        $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $toolCallsMade = [];
        $apiMessages = $messages;

        // Get available tool definitions
        $tools = $this->toolRegistry->getToolDefinitions();

        try {
            $done = false;
            for ($toolRound = 0; $toolRound <= $maxToolRounds && ! $done; $toolRound++) {
                $roundHasToolCalls = false;

                $payload = [
                    'model' => $resolvedModel,
                    'messages' => $apiMessages,
                    'max_tokens' => $maxTokens,
                ];

                if (! empty($tools)) {
                    $payload['tools'] = $tools;
                }

                $response = Http::withHeaders($this->buildHeaders())
                    ->timeout($this->getTimeout())
                    ->post($this->buildUrl(), $payload);

                $responseTimeMs = $this->elapsedMs($startTime);
                $responseData = $response->json();
                $usage = $this->normalizeUsage($responseData['usage'] ?? null);

                foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
                    $totalUsage[$key] += $usage[$key] ?? 0;
                }

                $errorResult = AiResponseHandler::checkErrors($response, $resolvedModel, $startTime);
                if ($errorResult) {
                    return AgenticChatResult::failure($errorResult->error, $errorResult->model, $errorResult->responseTimeMs);
                }

                $responseMessage = $responseData['choices'][0]['message'] ?? [];
                $content = $responseMessage['content'] ?? '';
                $toolCalls = $responseMessage['tool_calls'] ?? [];

                // Handle tool calls
                if (! empty($toolCalls)) {
                    $roundHasToolCalls = true;

                    if (! blank($content)) {
                        $fullContent .= $content;
                    }

                    // Add assistant message with tool_calls
                    $assistantMessage = ['role' => 'assistant'];
                    if (! blank($content)) {
                        $assistantMessage['content'] = $content;
                    }
                    $assistantMessage['tool_calls'] = $toolCalls;
                    $apiMessages[] = $assistantMessage;

                    // Execute each tool
                    foreach ($toolCalls as $toolCall) {
                        $toolResult = $this->toolRegistry->executeToolCall($toolCall);
                        $apiMessages[] = $toolResult;

                        $toolCallsMade[] = [
                            'round' => $toolRound,
                            'name' => $toolCall['function']['name'] ?? 'unknown',
                            'arguments' => $toolCall['function']['arguments'] ?? '{}',
                            'result_length' => strlen($toolResult['content'] ?? ''),
                        ];
                    }

                    continue; // Next tool round
                }

                // No tool calls — this is the final response
                if (blank($content)) {
                    if ($toolRound === 0) {
                        return AgenticChatResult::failure('AI returned empty response.', $resolvedModel, $responseTimeMs);
                    }
                    // If content is blank after tool rounds, use accumulated content
                } else {
                    $fullContent .= $content;
                }

                $done = true;
            }

            if (blank($fullContent)) {
                return AgenticChatResult::failure('AI returned empty response.', $resolvedModel, $responseTimeMs);
            }

            $toolSummary = null;
            if (! empty($toolCallsMade)) {
                $toolNames = collect($toolCallsMade)->pluck('name')->unique()->implode(', ');
                $toolSummary = "Used tools: {$toolNames}";
            }

            return AgenticChatResult::success(
                text: $fullContent,
                model: $resolvedModel,
                promptTokens: $totalUsage['prompt_tokens'],
                completionTokens: $totalUsage['completion_tokens'],
                totalTokens: $totalUsage['total_tokens'],
                responseTimeMs: $responseTimeMs,
                apiRequestId: $responseData['id'] ?? null,
                toolCallsMade: $toolCallsMade,
                toolSummary: $toolSummary,
            );

        } catch (ConnectionException $e) {
            $responseTimeMs = $this->elapsedMs($startTime);

            return AgenticChatResult::failure('Cannot connect to AI service.', $resolvedModel, $responseTimeMs);
        } catch (\Throwable $e) {
            $responseTimeMs = $this->elapsedMs($startTime);
            Log::warning('Agentic chat unexpected error', ['error' => $e->getMessage()]);

            return AgenticChatResult::failure('Unexpected error: '.$e->getMessage(), $resolvedModel, $responseTimeMs);
        }
    }
}
