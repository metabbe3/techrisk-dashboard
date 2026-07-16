<?php

namespace App\Http\Controllers\Ai;

use App\Models\AiSetting;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Incident;
use App\Models\WarRoomAgentConfig;
use App\Services\Ai\AiChatService;
use App\Services\Ai\AiTextResult;
use App\Services\Ai\AiUsageLogger;
use App\Services\Ai\ChatContextService;
use App\Services\Ai\PersonaStreamingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatPersonaStreamController
{
    public function __construct(
        private ChatContextService $contextService,
        private PersonaStreamingService $personaStreamingService,
        private AiUsageLogger $aiUsageLogger,
        private AiChatService $aiChatService,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $request->validate([
            'message' => 'required|string|max:5000',
            'conversation_id' => 'nullable|uuid',
            'model' => 'nullable|string',
            'referenced_incidents' => 'nullable|array',
            'referenced_incidents.*' => 'string',
            'personas' => 'required|array|min:1',
            'personas.*' => 'string|exists:war_room_agent_configs,role_key',
            'web_search' => 'nullable|boolean',
            'attachments' => 'nullable|array',
            'attachments.*.id' => 'required|string',
            'attachments.*.type' => 'required|string',
            'attachments.*.filename' => 'nullable|string',
            'attachments.*.mime_type' => 'nullable|string',
            'attachments.*.size' => 'nullable|integer',
        ]);

        $userId = auth()->id() ?? 'guest';

        $userMessage = $request->input('message');
        $conversationId = $request->input('conversation_id');
        $model = $request->input('model');
        $referencedIds = $request->input('referenced_incidents', []);
        $rawAttachments = $request->input('attachments', []);
        $personaKeys = $request->input('personas', []);

        // Persona mode fires one AI call per selected agent, so the rate limit is
        // charged per call (one hit per persona), not per message — keeping it
        // cost-aligned with the default single-call chat path.
        $personaCallBudget = (int) config('ai.rate_limit.persona_calls_per_min', 30);
        $personaLimiterKey = "ai-chat-persona:{$userId}";
        if (RateLimiter::remaining($personaLimiterKey, $personaCallBudget) < count($personaKeys)) {
            return new StreamedResponse(function () {
                echo 'data: '.json_encode(['error' => 'Rate limit exceeded. Please wait a moment.'])."\n\n";
            }, 429, ['Content-Type' => 'text/event-stream']);
        }
        foreach (range(1, count($personaKeys)) as $_) {
            RateLimiter::hit($personaLimiterKey);
        }

        $slashCommand = null;
        $slashArgs = '';
        if (preg_match('/^\/(\w+)(?:\s+(.*))?/', $userMessage, $match)) {
            $slashCommand = strtolower($match[1]);
            $slashArgs = $match[2] ?? '';
        }

        $isNew = ! $conversationId;
        $conversation = $conversationId
            ? ChatConversation::where('id', $conversationId)->where('user_id', auth()->id())->firstOrFail()
            : ChatConversation::create([
                'user_id' => auth()->id(),
                'title' => $slashCommand ? '/'.$slashCommand : mb_substr($userMessage, 0, 80),
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

        $history = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->take(config('ai.chat_max_history', 20))
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        if (empty($referencedIds)) {
            $historyText = collect($history)->map(fn ($m) => $m['content'])->implode(' ');
            if (preg_match_all(Incident::ID_PATTERN, $historyText, $historyMatches)) {
                $referencedIds = array_unique($historyMatches[0]);
            }
        }

        $searchEnriched = false;
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

        if (! $slashCommand && preg_match('/(?:\/search\b|\bsearch\s+(?:the\s+)?(?:web|internet|online)|look\s+up|check\s+online|\bsearch\s+for)\b/i', $userMessage)) {
            $searchContext = $this->contextService->getSearchContextFromMessage($userMessage, $referencedIds);
            if ($searchContext) {
                $userMessage .= "\n\n".$searchContext;
                $userMessage .= "\n\nThe user wants external web references combined with internal incident data. Always cite external sources using markdown links.";
                $searchEnriched = true;
            }
        }

        if ($request->boolean('web_search') && ! $searchEnriched && $slashCommand !== 'search') {
            $searchContext = $this->contextService->getSearchContextFromMessage($userMessage, $referencedIds);
            if ($searchContext) {
                $userMessage .= "\n\n".$searchContext;
                $userMessage .= "\n\nSupplementary web search results are included above. Integrate external references with internal data where relevant. Cite external sources using markdown links.";
                $searchEnriched = true;
            }
        }

        $personas = WarRoomAgentConfig::whereIn('role_key', $personaKeys)->ordered()->get();
        $baseUrl = rtrim(AiSetting::get('base_url', config('ai.base_url', '')), '/');
        $apiKey = AiSetting::get('api_key', config('ai.api_key', ''));
        $timeout = (int) AiSetting::get('timeout', config('ai.timeout', 60));
        $maxTokens = config('ai.chat_max_tokens', 4000);

        $conversationIdStr = (string) $conversation->id;
        $userMsgIdStr = (string) $userMsg->id;
        $lastPersonaKey = $personas->last()->role_key;

        return new StreamedResponse(function () use ($personas, $baseUrl, $apiKey, $model, $history, $userMessage, $maxTokens, $timeout, $referencedIds, $conversation, $conversationIdStr, $userMsgIdStr, $isNew, $searchEnriched, $request, $rawAttachments, $lastPersonaKey) {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', false);

            echo "event: setup\ndata: ".json_encode([
                'conversation_id' => $conversationIdStr,
                'user_message_id' => $userMsgIdStr,
                'is_new' => $isNew,
                'mode' => 'personas',
                'persona_count' => $personas->count(),
            ])."\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();

            $emitSse = function (string $event, array $data): void {
                if ($event === 'delta') {
                    echo 'data: '.json_encode($data)."\n\n";
                } else {
                    echo "event: {$event}\ndata: ".json_encode($data)."\n\n";
                }
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            $service = $this->personaStreamingService;
            $results = $service->streamConcurrent(
                personas: $personas->all(),
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                defaultModel: $model,
                history: $history,
                userMessage: $userMessage,
                maxTokens: $maxTokens,
                timeout: $timeout,
                rawAttachments: $rawAttachments,
                emitSse: $emitSse,
                contextService: $this->contextService,
                referencedIds: $referencedIds,
            );

            $lastResponseText = null;

            foreach ($results as $key => $result) {
                $persona = $result['persona'];
                $resolvedModel = $result['model'];
                $responseTimeMs = $result['responseTimeMs'];

                if ($result['failed'] || blank($result['fullContent'] ?? null)) {
                    $errorMsg = $result['error'] ?? 'Unknown error';
                    $usage = $result['usage'] ?? [];

                    if (blank($result['fullContent'] ?? null) && ! $result['failed']) {
                        $rawError = null;
                        $rawBody = $result['rawBody'] ?? '';
                        if (! blank($rawBody)) {
                            $errorData = json_decode($rawBody, true);
                            $rawError = $errorData['error']['message'] ?? ($errorData['message'] ?? $rawBody);
                        }
                        if ($rawError) {
                            $errorMsg = 'AI error: '.$rawError;
                        } elseif (! blank($result['reasoningContent'] ?? '') && ($result['finishReason'] ?? null) === 'length') {
                            $errorMsg = 'Model used all tokens on reasoning. Try a simpler question or different model.';
                        } else {
                            $errorMsg = 'AI returned an empty response. Try rephrasing your question.';
                        }

                        Log::warning('AI persona returned empty response', [
                            'persona' => $key,
                            'model' => $resolvedModel,
                            'finish_reason' => $result['finishReason'] ?? null,
                        ]);

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
                            errorMessage: 'Empty streaming response for persona '.$key,
                            metadata: ['persona' => $key, 'mode' => 'persona_stream'],
                        );
                    } else {
                        Log::warning('AI persona stream error', ['persona' => $key, 'error' => $errorMsg]);
                    }

                    $emitSse('persona_error', [
                        'persona_key' => $key,
                        'error' => $errorMsg,
                    ]);

                    continue;
                }

                $isLast = $key === $lastPersonaKey;
                $responseText = $result['fullContent'];
                $usage = $result['usage'] ?? [];

                // Extract follow-up from last persona only
                $followUps = null;
                if ($isLast) {
                    if (preg_match('/<!--FOLLOW_UP:(\[.*?\])-->/', $responseText, $followMatch)) {
                        $decoded = json_decode($followMatch[1], true);
                        if (is_array($decoded)) {
                            $followUps = $decoded;
                        }
                        $responseText = trim(str_replace($followMatch[0], '', $responseText));
                    }
                } else {
                    $responseText = preg_replace('/<!--FOLLOW_UP:\[.*?\]-->/', '', $responseText);
                    $responseText = trim($responseText);
                }

                $lastResponseText = $responseText;

                $assistantMessage = ChatMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $responseText,
                    'model' => $resolvedModel,
                    'persona_key' => $persona->role_key,
                    'persona_name' => $persona->display_name,
                    'persona_icon' => $persona->icon,
                    'persona_color' => $persona->color,
                    'tokens_used' => $usage['total_tokens'] ?? null,
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'web_search_used' => $searchEnriched,
                    'created_at' => now(),
                ]);

                $this->aiUsageLogger->logFromResult(
                    fieldType: 'chat_assistant',
                    model: $resolvedModel,
                    result: new AiTextResult(
                        success: true,
                        text: $responseText,
                        model: $resolvedModel,
                        promptTokens: $usage['prompt_tokens'] ?? null,
                        completionTokens: $usage['completion_tokens'] ?? null,
                        totalTokens: $usage['total_tokens'] ?? null,
                        responseTimeMs: $responseTimeMs,
                    ),
                    inputLength: strlen($request->input('message')),
                    metadata: ['message_id' => (string) $assistantMessage->id, 'persona' => $persona->role_key],
                );

                $emitSse('persona_metadata', [
                    'persona_key' => $persona->role_key,
                    'message_id' => (string) $assistantMessage->id,
                    'model' => $resolvedModel,
                    'usage' => $usage,
                    'response_time_ms' => $responseTimeMs,
                    'follow_ups' => $followUps,
                    'full_content' => $responseText,
                    'finish_reason' => $result['finishReason'] ?? null,
                    'truncated' => ($result['finishReason'] ?? null) === 'length',
                ]);
            }

            $conversation->update(['updated_at' => now()]);

            $updatedTitle = null;
            if ($isNew) {
                $chatService = $this->aiChatService;
                $updatedTitle = $chatService->generateTitle($request->input('message'), $lastResponseText ?? null);
                if ($updatedTitle) {
                    $conversation->update(['title' => $updatedTitle]);
                }
            }

            $freshness = $this->contextService->getDataFreshness();

            echo "event: done\ndata: ".json_encode([
                'conversation_id' => $conversationIdStr,
                'updated_title' => $updatedTitle,
                'data_freshness' => $freshness,
                'web_search_used' => $searchEnriched,
            ])."\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
