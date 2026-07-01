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
                    $userMessage .= $enriched['extra_context'];
                }
            }
        }

        // Detect inline web search intent (without /search command at message start)
        if (! $slashCommand && preg_match('/(?:\/search\b|\bsearch\s+(?:the\s+)?(?:web|internet|online)|look\s+up|check\s+online|\bsearch\s+for)\b/i', $userMessage)) {
            $searchContext = $this->contextService->getSearchContextFromMessage($userMessage, $referencedIds);
            if ($searchContext) {
                $userMessage .= "\n\n".$searchContext;
                $userMessage .= "\n\nThe user wants external web references combined with internal incident data. Always cite external sources using markdown links.";
                $searchEnriched = true;
            }
        }

        // Force web search when toggle is ON and not already enriched
        if ($request->boolean('web_search') && ! $searchEnriched && $slashCommand !== 'search') {
            $searchContext = $this->contextService->getSearchContextFromMessage($userMessage, $referencedIds);
            if ($searchContext) {
                $userMessage .= "\n\n".$searchContext;
                $userMessage .= "\n\nSupplementary web search results are included above. Integrate external references with internal data where relevant. Cite external sources using markdown links.";
            }
        }

        // Build API messages
        $systemPrompt = $this->contextService->buildSystemPrompt($userMessage, $referencedIds);
        $resolvedModel = $model ?? AiSetting::get('default_model', config('ai.default_model'));
        $maxTokens = config('ai.chat_max_tokens', 4000);

        $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];

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

            echo "event: setup\ndata: ".json_encode([
                'conversation_id' => $conversationIdStr,
                'user_message_id' => $userMsgIdStr,
                'is_new' => $isNew,
            ])."\n\n";

            if (ob_get_level()) {
                ob_flush();
            }
            flush();

            $sseService = $this->sseStreamingService;
            $fullContent = '';

            try {
                $result = $sseService->stream(
                    $baseUrl,
                    $apiKey,
                    [
                        'model' => $resolvedModel,
                        'messages' => $apiMessages,
                        'max_tokens' => $maxTokens,
                        'stream' => true,
                    ],
                    $timeout,
                    function (string $delta) use (&$fullContent) {
                        $fullContent .= $delta;
                        echo 'data: '.json_encode(['delta' => $delta])."\n\n";
                        if (ob_get_level()) {
                            ob_flush();
                        }
                        flush();
                    },
                );
            } catch (\Throwable $e) {
                Log::warning('AI stream error', ['error' => $e->getMessage()]);
                echo "event: error\ndata: ".json_encode(['error' => $e->getMessage()])."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();

                return;
            }

            $httpCode = $result['http_code'];
            $usage = $result['usage'];
            $finishReason = $result['finish_reason'];
            $responseTimeMs = $result['response_time_ms'];
            $fullContent = $result['content'];

            if ($result['error'] || $httpCode >= 400) {
                $errorMsg = $result['error'] ?: 'AI service error (HTTP '.$httpCode.')';
                echo "event: error\ndata: ".json_encode(['error' => $errorMsg])."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();

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
                echo "event: error\ndata: ".json_encode([
                    'error' => $userError,
                ])."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
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
            echo "event: metadata\ndata: ".json_encode([
                'full_content' => $fullContent,
                'usage' => $usage,
                'model' => $resolvedModel,
                'response_time_ms' => $responseTimeMs,
                'finish_reason' => $finishReason,
                'truncated' => $finishReason === 'length',
            ])."\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();

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

            // Archive conversation memory (async, non-blocking)
            try {
                $memoryService = $this->conversationMemoryService;
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
}
