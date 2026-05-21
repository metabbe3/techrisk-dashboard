<?php

namespace App\Http\Controllers\Ai;

use App\Models\AiSetting;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Incident;
use App\Models\WarRoomAgentConfig;
use App\Services\Ai\ChatContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatPersonaStreamController
{
    public function __construct(
        private ChatContextService $contextService,
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
        ]);

        $userId = auth()->id() ?? 'guest';
        if (! RateLimiter::attempt("ai-chat-persona:{$userId}", 20, fn () => true)) {
            return new StreamedResponse(function () {
                echo 'data: '.json_encode(['error' => 'Rate limit exceeded. Please wait a moment.'])."\n\n";
            }, 429, ['Content-Type' => 'text/event-stream']);
        }

        $userMessage = $request->input('message');
        $conversationId = $request->input('conversation_id');
        $model = $request->input('model');
        $referencedIds = $request->input('referenced_incidents', []);
        $personaKeys = $request->input('personas', []);

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

        $userMsg = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $request->input('message'),
            'created_at' => now(),
        ]);

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

        return new StreamedResponse(function () use ($personas, $baseUrl, $apiKey, $model, $history, $userMessage, $maxTokens, $timeout, $referencedIds, $conversation, $conversationIdStr, $userMsgIdStr, $isNew, $searchEnriched, $request) {
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

            foreach ($personas as $index => $persona) {
                $isLast = $index === $personas->count() - 1;

                echo "event: persona_start\ndata: ".json_encode([
                    'persona' => [
                        'key' => $persona->role_key,
                        'name' => $persona->display_name,
                        'icon' => $persona->icon,
                        'color' => $persona->color,
                    ],
                ])."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();

                $resolvedModel = $persona->model_override ?? $model ?? AiSetting::get('default_model', config('ai.default_model'));
                $systemPrompt = $this->contextService->buildPersonaSystemPrompt($persona, $userMessage, $referencedIds);
                $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];

                $maxHistory = config('ai.chat_max_history', 20);
                $historyMessages = array_slice($history, -$maxHistory);
                foreach ($historyMessages as $msg) {
                    $apiMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
                }

                for ($i = count($apiMessages) - 1; $i >= 1; $i--) {
                    if (($apiMessages[$i]['role'] ?? '') === 'user') {
                        $apiMessages[$i]['content'] = $userMessage;
                        break;
                    }
                }

                $fullContent = '';
                $usage = [];
                $startTime = microtime(true);

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $baseUrl.'/chat/completions',
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer '.$apiKey,
                        'Content-Type: application/json',
                        'Accept: text/event-stream',
                    ],
                    CURLOPT_POSTFIELDS => json_encode([
                        'model' => $resolvedModel,
                        'messages' => $apiMessages,
                        'max_tokens' => $maxTokens,
                        'stream' => true,
                    ]),
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$fullContent, &$usage, $persona) {
                        $lines = explode("\n", $data);
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (str_starts_with($line, 'data: ')) {
                                $json = substr($line, 6);
                                if ($json === '[DONE]') {
                                    echo "event: persona_done\ndata: ".json_encode([
                                        'persona_key' => $persona->role_key,
                                    ])."\n\n";
                                    if (ob_get_level()) {
                                        ob_flush();
                                    }
                                    flush();

                                    return strlen($data);
                                }

                                $parsed = json_decode($json, true);
                                if (! $parsed) {
                                    continue;
                                }

                                $delta = $parsed['choices'][0]['delta']['content'] ?? '';
                                if ($delta !== '') {
                                    $fullContent .= $delta;
                                    echo 'data: '.json_encode([
                                        'delta' => $delta,
                                        'persona_key' => $persona->role_key,
                                    ])."\n\n";
                                    if (ob_get_level()) {
                                        ob_flush();
                                    }
                                    flush();
                                }

                                if (isset($parsed['usage'])) {
                                    $usage = $parsed['usage'];
                                }
                            }
                        }

                        return strlen($data);
                    },
                ]);

                $success = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                $responseTimeMs = (microtime(true) - $startTime) * 1000;

                if (! $success || $httpCode >= 400) {
                    $errorMsg = $curlError ?: 'AI service error (HTTP '.$httpCode.')';
                    Log::warning('AI persona stream error', ['persona' => $persona->role_key, 'http_code' => $httpCode]);
                    echo "event: persona_error\ndata: ".json_encode([
                        'persona_key' => $persona->role_key,
                        'error' => $errorMsg,
                    ])."\n\n";
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();

                    continue;
                }

                // Extract follow-up from last persona only
                $followUps = null;
                $responseText = $fullContent;
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

                app(\App\Services\Ai\AiUsageLogger::class)->logFromResult(
                    fieldType: 'chat_assistant',
                    model: $resolvedModel,
                    result: new \App\Services\Ai\AiTextResult(
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

                echo "event: persona_metadata\ndata: ".json_encode([
                    'persona_key' => $persona->role_key,
                    'message_id' => (string) $assistantMessage->id,
                    'model' => $resolvedModel,
                    'usage' => $usage,
                    'response_time_ms' => $responseTimeMs,
                    'follow_ups' => $followUps,
                    'full_content' => $responseText,
                ])."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }

            $conversation->update(['updated_at' => now()]);

            $updatedTitle = null;
            if ($isNew) {
                $chatService = app(\App\Services\Ai\AiChatService::class);
                $updatedTitle = $chatService->generateTitle($request->input('message'), $responseText ?? null);
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
