<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Orchestrates the streaming (SSE) chat send: conversation resolution, enrichment,
 * the streamed AI call, server-side persistence, and usage logging. Extracted from
 * ChatStreamController per the service-layer mandate. Streamed bytes are unchanged.
 */
class ChatStreamService
{
    public function __construct(
        private ChatContextService $contextService,
        private ChatAttachmentService $attachmentService,
        private SseStreamingService $sseStreamingService,
        private AiUsageLogger $aiUsageLogger,
        private ConversationMemoryService $conversationMemoryService,
        private ToolRegistryService $toolRegistry,
    ) {}

    public function handle(Request $request): StreamedResponse
    {
        $request->validate([
            'message' => 'required|string|max:5000',
            'conversation_id' => 'nullable|uuid',
            'model' => 'nullable|string',
            'referenced_incidents' => 'nullable|array',
            'referenced_incidents.*' => 'string',
            'web_search' => 'nullable|boolean',
            'attachments' => 'nullable|array',
            'attachments.*.id' => 'required|string',
            'attachments.*.type' => 'required|string',
            'attachments.*.filename' => 'nullable|string',
            'attachments.*.mime_type' => 'nullable|string',
            'attachments.*.size' => 'nullable|integer',
            'mode' => 'nullable|string|in:normal',
        ]);

        $userMessage = $request->input('message');
        $conversationId = $request->input('conversation_id');
        $referencedIds = $request->input('referenced_incidents', []);
        $model = $request->input('model');
        $rawAttachments = $request->input('attachments', []);

        $userId = auth()->id() ?? 'guest';
        if (! RateLimiter::attempt("ai-chat:{$userId}", 6, fn () => true)) {
            return new StreamedResponse(function () {
                echo 'data: '.json_encode(['error' => 'Rate limit exceeded. Please wait a moment.'])."\n\n";
            }, 429, ['Content-Type' => 'text/event-stream']);
        }

        // Detect slash command for title extraction (before conversation creation)
        $slashCommand = null;
        $slashArgs = '';
        if (preg_match('/^\/(\w+)(?:\s+(.*))?/', $userMessage, $match)) {
            $slashCommand = strtolower($match[1]);
            $slashArgs = $match[2] ?? '';
        }

        // Resolve conversation
        $conversation = $conversationId
            ? ChatConversation::where('id', $conversationId)->where('user_id', auth()->id())->firstOrFail()
            : ChatConversation::create([
                'user_id' => auth()->id(),
                'title' => $slashCommand ? '/'.$slashCommand : mb_substr($request->input('message'), 0, 80),
                'model' => $model,
            ]);

        // Guardrails: per-conversation message cap + token budget. Existing convos only
        // (a brand-new one can't be over yet). Single aggregate query — a transactional
        // lockForUpdate would be race-safe but risks deadlocks for a soft cap, so we
        // accept a negligible boundary race. ponytail: soft cap, not a hard lock.
        if ($conversationId) {
            $maxMessages = (int) config('ai.rate_limit.conversation_max_messages', 200);
            $tokenBudget = (int) config('ai.rate_limit.conversation_token_budget', 500000);
            $agg = $conversation->messages()
                ->selectRaw('COUNT(*) AS msg_count, COALESCE(SUM(tokens_used), 0) AS tokens_used')
                ->first();
            if (($agg->msg_count ?? 0) >= $maxMessages || ($agg->tokens_used ?? 0) >= $tokenBudget) {
                $reason = ($agg->msg_count ?? 0) >= $maxMessages
                    ? "This conversation reached its {$maxMessages}-message limit. Please start a new conversation."
                    : "This conversation reached its token budget ({$tokenBudget}). Please start a new conversation.";

                return new StreamedResponse(function () use ($reason) {
                    echo 'data: '.json_encode(['error' => $reason])."\n\n";
                }, 422, ['Content-Type' => 'text/event-stream']);
            }
        }

        $createData = [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $request->input('message'),
            'created_at' => now(),
        ];
        if (! empty($rawAttachments)) {
            $createData['attachments'] = $rawAttachments;
        }
        $userMsg = ChatMessage::create($createData);

        // Load history BEFORE enrichment so /search can use conversation context
        $history = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->take(config('ai.chat_max_history', 20))
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        $searchEnriched = false;

        // If no referenced incidents sent, scan conversation history for previously mentioned IDs
        if (empty($referencedIds)) {
            $historyText = collect($history)->map(fn ($m) => $m['content'])->implode(' ');
            if (preg_match_all('/\d{4}_(?:IN|IS)_\d{4}/', $historyText, $historyMatches)) {
                $referencedIds = array_unique($historyMatches[0]);
            }
        }

        // NOW enrich with slash commands (has full history context)
        if ($slashCommand) {
            $commands = config('ai.chat_slash_commands', []);
            if (isset($commands[$slashCommand])) {
                $enriched = $this->contextService->enrichSlashCommand($slashCommand, $slashArgs, $referencedIds);
                $userMessage = $enriched['message'];
                if (! empty($enriched['extra_context'])) {
                    $userMessage .= $this->contextService->fenceUntrusted($enriched['extra_context'], 'Retrieved context');
                }
            }
        }

        // Web search is opt-in only: via the explicit `web_search` toggle or the
        // `/search` slash command (handled above). The brittle free-text intent
        // regex ("search the web", "look up", …) was removed — it false-triggered
        // and miss-fired; the toggle is unambiguous.

        // Force web search when toggle is ON and not already enriched
        if ($request->boolean('web_search') && ! $searchEnriched && $slashCommand !== 'search') {
            $searchContext = $this->contextService->getSearchContextFromMessage($userMessage, $referencedIds);
            if ($searchContext) {
                // Fence web results as untrusted data (prompt-injection defense).
                $userMessage .= $this->contextService->fenceUntrusted($searchContext, 'Retrieved web results');
                $userMessage .= "\n\nSupplementary web search results are included above. Integrate external references with internal data where relevant. Cite external sources using markdown links.";
            }
        }

        // Build API messages
        $systemPrompt = $this->contextService->buildSystemPrompt($userMessage, $referencedIds);

        // Health-aware model routing: classify the turn's intent (thinking vs
        // rephrase) and pick a healthy model for that tier. The user's manual UI
        // pick ($model) is the preferred — used unless it's currently unhealthy.
        $router = app(ModelRouter::class);
        $resolvedModel = $router->pick(
            $router->tierForIntent($userMessage),
            $model ?? AiSetting::get('default_model', config('ai.default_model')),
        );
        $maxTokens = config('ai.chat_max_tokens', 4000);

        $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];

        // Context compaction: carry the archived conversation summary so early detail
        // isn't dropped when the 20-message history window truncates it.
        if (! empty($conversation->summary)) {
            $apiMessages[] = ['role' => 'system', 'content' => 'Prior conversation summary (for continuity): '.$conversation->summary];
        }

        foreach ($history as $msg) {
            $apiMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        // Process attachments into multimodal content for the last user message
        if (! empty($rawAttachments)) {
            $attachmentService = $this->attachmentService;
            $lastIdx = count($apiMessages) - 1;
            for ($i = count($apiMessages) - 1; $i >= 1; $i--) {
                if (($apiMessages[$i]['role'] ?? '') === 'user') {
                    $lastIdx = $i;
                    break;
                }
            }
            $apiMessages[$lastIdx]['content'] = $attachmentService->buildMessageContent(
                $apiMessages[$lastIdx]['content'],
                $rawAttachments
            );
        }

        $baseUrl = rtrim(AiSetting::get('base_url', config('ai.base_url', '')), '/');
        $apiKey = AiSetting::get('api_key', config('ai.api_key', ''));
        $timeout = (int) AiSetting::get('timeout', config('ai.timeout', 60));

        $conversationIdStr = (string) $conversation->id;
        $userMsgIdStr = (string) $userMsg->id;
        $isNew = ! $conversationId;

        return new StreamedResponse(function () use ($baseUrl, $apiKey, $resolvedModel, $apiMessages, $maxTokens, $timeout, $conversationIdStr, $userMsgIdStr, $isNew, $searchEnriched, $conversation) {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', false);

            $this->emit('setup', [
                'conversation_id' => $conversationIdStr,
                'user_message_id' => $userMsgIdStr,
                'is_new' => $isNew,
            ]);

            $sseService = $this->sseStreamingService;
            $toolRegistry = $this->toolRegistry;
            $toolDefs = config('ai.tools.enabled', true) ? $toolRegistry->getToolDefinitions() : [];
            $maxToolRounds = (int) config('ai.tools.chat_max_rounds', 3);
            $fullContent = '';
            $allToolCalls = [];
            $apiMsgs = $apiMessages;
            $result = null;

            try {
                // Agentic tool-calling loop: stream → if tool_calls, execute + emit
                // events → append results → stream again. Final round (no tool_calls)
                // streams the answer tokens directly to the client.
                for ($round = 0; $round <= $maxToolRounds; $round++) {
                    $payload = [
                        'model' => $resolvedModel,
                        'messages' => $apiMsgs,
                        'max_tokens' => $maxTokens,
                        'stream' => true,
                    ];
                    if (! empty($toolDefs)) {
                        $payload['tools'] = $toolDefs;
                    }

                    $fullContent = '';

                    $result = $sseService->stream(
                        $baseUrl,
                        $apiKey,
                        $payload,
                        $timeout,
                        function (string $delta) use (&$fullContent) {
                            $fullContent .= $delta;
                            $this->emit(null, ['delta' => $delta]);
                        },
                    );

                    if ($result['error'] || $result['http_code'] >= 400) {
                        $errorMsg = $result['error'] ?: 'AI service error (HTTP '.$result['http_code'].')';
                        $this->emit('error', ['error' => $errorMsg]);

                        return;
                    }

                    $toolCalls = $result['tool_calls'] ?? [];

                    // No tool calls = final answer (already streamed via deltas).
                    if (empty($toolCalls)) {
                        break;
                    }

                    // Execute tool calls, emit events, append results for next round.
                    $assistantMsg = ['role' => 'assistant'];
                    if (! blank($result['content'])) {
                        $assistantMsg['content'] = $result['content'];
                    }
                    $assistantMsg['tool_calls'] = $toolCalls;
                    $apiMsgs[] = $assistantMsg;

                    foreach ($toolCalls as $tc) {
                        $toolName = $tc['function']['name'] ?? 'unknown';
                        $toolArgs = $tc['function']['arguments'] ?? '{}';

                        $this->emit('tool_call', [
                            'name' => $toolName,
                            'arguments' => json_decode($toolArgs, true) ?: $toolArgs,
                        ]);

                        $toolResult = $toolRegistry->executeToolCall($tc);
                        $resultPreview = mb_substr($toolResult['content'] ?? 'OK', 0, 500);

                        $this->emit('tool_result', [
                            'name' => $toolName,
                            'result' => $resultPreview,
                        ]);

                        $apiMsgs[] = $toolResult;
                        $allToolCalls[] = [
                            'name' => $toolName,
                            'arguments' => $toolArgs,
                            'result_length' => strlen($toolResult['content'] ?? ''),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('AI stream error', ['error' => $e->getMessage()]);
                $this->emit('error', ['error' => $e->getMessage()]);

                return;
            }

            $httpCode = $result['http_code'];
            $usage = $result['usage'];
            $finishReason = $result['finish_reason'];
            $responseTimeMs = $result['response_time_ms'];
            $fullContent = $result['content'];

            if ($result['error'] || $httpCode >= 400) {
                $errorMsg = $result['error'] ?: 'AI service error (HTTP '.$httpCode.')';
                $this->emit('error', ['error' => $errorMsg]);

                return;
            }

            if (blank($fullContent)) {
                $rawBody = $result['raw_body'] ?? '';
                $rawError = null;
                if (! blank($rawBody)) {
                    $errorData = json_decode($rawBody, true);
                    $rawError = $errorData['error']['message'] ?? ($errorData['message'] ?? $rawBody);
                }
                $userError = $rawError
                    ? 'AI error: '.$rawError
                    : 'AI returned an empty response. Try rephrasing your question.';
                Log::warning('AI stream returned empty response', [
                    'model' => $resolvedModel,
                    'finish_reason' => $finishReason,
                    'http_code' => $httpCode,
                    'usage' => $usage,
                    'raw_error' => $rawBody ?: null,
                ]);
                $this->emit('error', [
                    'error' => $userError,
                ]);
                try {
                    $this->aiUsageLogger->log(
                        fieldType: 'chat_assistant',
                        model: $resolvedModel,
                        success: false,
                        outputLength: 0,
                        usage: array_filter([
                            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                            'completion_tokens' => $usage['completion_tokens'] ?? null,
                            'total_tokens' => $usage['total_tokens'] ?? null,
                        ]),
                        responseTimeMs: $responseTimeMs,
                        apiRequestId: $usage['id'] ?? null,
                        errorMessage: 'Empty streaming response',
                        metadata: ['message_id' => $userMsgIdStr, 'mode' => 'stream'],
                    );
                } catch (\Throwable $e) {
                    Log::warning('Failed to log empty streaming chat usage', ['error' => $e->getMessage()]);
                }

                return;
            }

            // Send metadata event with full content and usage
            $this->emit('metadata', [
                'full_content' => $fullContent,
                'usage' => $usage,
                'model' => $resolvedModel,
                'response_time_ms' => $responseTimeMs,
                'finish_reason' => $finishReason,
                'truncated' => $finishReason === 'length',
            ]);

            // Save assistant message to database (server-side persistence)
            try {
                ChatMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $fullContent,
                    'model' => $resolvedModel,
                    'tokens_used' => $usage['total_tokens'] ?? null,
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'web_search_used' => $searchEnriched,
                    'tool_calls' => ! empty($allToolCalls) ? $allToolCalls : null,
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to save streaming assistant message', ['error' => $e->getMessage()]);
            }

            // Log usage after stream completes
            try {
                $this->aiUsageLogger->log(
                    fieldType: 'chat_assistant',
                    model: $resolvedModel,
                    success: ! blank($fullContent),
                    outputLength: strlen($fullContent),
                    usage: array_filter([
                        'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                        'completion_tokens' => $usage['completion_tokens'] ?? null,
                        'total_tokens' => $usage['total_tokens'] ?? null,
                    ]),
                    responseTimeMs: $responseTimeMs,
                    apiRequestId: $usage['id'] ?? null,
                    errorMessage: blank($fullContent) ? 'Empty streaming response' : null,
                    metadata: ['message_id' => $userMsgIdStr, 'mode' => 'stream'],
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to log streaming chat usage', ['error' => $e->getMessage()]);
            }

            // Archive / re-summarize conversation memory. archiveConversation() only
            // summarizes once (guards on memory_archived_at); reset the flag every N
            // messages so long chats get a refreshed summary for context compaction.
            try {
                $memoryService = $this->conversationMemoryService;
                $interval = (int) config('ai.memory.compaction_interval', 0);
                if ($interval > 0) {
                    $msgCount = $conversation->messages()->count();
                    if ($msgCount >= $interval && $msgCount % $interval === 0) {
                        $conversation->update(['memory_archived_at' => null]);
                    }
                }
                $memoryService->archiveConversation($conversation);
            } catch (\Throwable $e) {
                Log::debug('Failed to archive conversation memory', ['error' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Emit one SSE frame and flush. Single chokepoint for the event/data framing
     * and the ob_flush/flush ceremony so every streamed event is shaped identically.
     */
    private function emit(?string $event, array $data): void
    {
        echo ($event !== null ? "event: {$event}\n" : '')
            .'data: '.json_encode($data)."\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
