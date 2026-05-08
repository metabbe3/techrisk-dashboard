<?php

namespace App\Http\Controllers\Ai;

use App\Models\AiSetting;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Ai\ChatContextService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class ChatStreamController
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
        ]);

        $userMessage = $request->input('message');
        $conversationId = $request->input('conversation_id');
        $referencedIds = $request->input('referenced_incidents', []);
        $model = $request->input('model');

        $userId = auth()->id() ?? 'guest';
        if (! RateLimiter::attempt("ai-chat:{$userId}", 6, fn () => true)) {
            return new StreamedResponse(function () {
                echo 'data: '.json_encode(['error' => 'Rate limit exceeded. Please wait a moment.'])."\n\n";
            }, 429, ['Content-Type' => 'text/event-stream']);
        }

        // Detect slash commands
        $slashCommand = null;
        if (preg_match('/^\/(\w+)(?:\s+(.*))?/', $userMessage, $match)) {
            $slashCommand = strtolower($match[1]);
            $slashArgs = $match[2] ?? '';
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
            }
        }

        // Resolve conversation
        $conversation = $conversationId
            ? ChatConversation::where('id', $conversationId)->where('user_id', auth()->id())->firstOrFail()
            : ChatConversation::create([
                'user_id' => auth()->id(),
                'title' => $slashCommand ? '/'.$slashCommand : mb_substr($request->input('message'), 0, 80),
                'model' => $model,
            ]);

        $userMsg = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $request->input('message'),
            'created_at' => now(),
        ]);

        // Build API messages
        $systemPrompt = $this->contextService->buildSystemPrompt($userMessage);
        $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];

        $history = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->take(config('ai.chat_max_history', 20))
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        foreach ($history as $msg) {
            $apiMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        $resolvedModel = $model ?? AiSetting::get('default_model', config('ai.default_model'));
        $baseUrl = rtrim(AiSetting::get('base_url', config('ai.base_url', '')), '/');
        $apiKey = AiSetting::get('api_key', config('ai.api_key', ''));
        $timeout = (int) AiSetting::get('timeout', config('ai.timeout', 60));
        $maxTokens = config('ai.chat_max_tokens', 4000);

        $conversationIdStr = (string) $conversation->id;
        $userMsgIdStr = (string) $userMsg->id;
        $isNew = ! $conversationId;

        return new StreamedResponse(function () use ($baseUrl, $apiKey, $resolvedModel, $apiMessages, $maxTokens, $timeout, $conversationIdStr, $userMsgIdStr, $isNew) {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', false);

            // Send initial setup event
            echo "event: setup\ndata: ".json_encode([
                'conversation_id' => $conversationIdStr,
                'user_message_id' => $userMsgIdStr,
                'is_new' => $isNew,
            ])."\n\n";

            if (ob_get_level()) {
                ob_flush();
            }
            flush();

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
                CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$fullContent, &$usage) {
                    $lines = explode("\n", $data);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (str_starts_with($line, 'data: ')) {
                            $json = substr($line, 6);
                            if ($json === '[DONE]') {
                                echo "data: [DONE]\n\n";
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
                                echo 'data: '.json_encode(['delta' => $delta])."\n\n";
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
                Log::warning('AI stream error', ['http_code' => $httpCode, 'curl_error' => $curlError]);
                echo "event: error\ndata: ".json_encode(['error' => $errorMsg])."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();

                return;
            }

            // Send metadata event with full content and usage
            echo "event: metadata\ndata: ".json_encode([
                'full_content' => $fullContent,
                'usage' => $usage,
                'model' => $resolvedModel,
                'response_time_ms' => $responseTimeMs,
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
