<?php

namespace App\Http\Controllers\Ai;

use App\Models\AiSetting;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\WarRoomAgentConfig;
use App\Services\Ai\AgenticChatService;
use App\Services\Ai\AiChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatSendController
{
    public function __construct(
        private AiChatService $chatService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:5000',
            'conversation_id' => 'nullable|uuid',
            'model' => 'nullable|string',
            'referenced_incidents' => 'nullable|array',
            'referenced_incidents.*' => 'string',
            'personas' => 'nullable|array',
            'personas.*' => 'string|exists:war_room_agent_configs,role_key',
            'web_search' => 'nullable|boolean',
        ]);

        $userMessage = $request->input('message');
        $conversationId = $request->input('conversation_id');
        $model = $request->input('model');
        $referencedIds = $request->input('referenced_incidents', []);
        $personaKeys = $request->input('personas', []);

        // Detect slash command for title extraction (before conversation creation)
        $slashCommand = null;
        $slashArgs = '';
        if (preg_match('/^\/(\w+)(?:\s+(.*))?/', $userMessage, $match)) {
            $slashCommand = strtolower($match[1]);
            $slashArgs = $match[2] ?? '';
        }

        $isNewConversation = ! $conversationId;
        $conversation = $conversationId
            ? ChatConversation::where('id', $conversationId)->where('user_id', auth()->id())->firstOrFail()
            : ChatConversation::create([
                'user_id' => auth()->id(),
                'title' => $slashCommand ? '/'.$slashCommand : mb_substr($request->input('message'), 0, 80),
                'model' => $model,
            ]);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $request->input('message'),
            'created_at' => now(),
        ]);

        // Load history BEFORE enrichment so /search can use conversation context
        $history = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->take(config('ai.chat_max_history', 20))
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        // If no referenced incidents sent, scan conversation history for previously mentioned IDs
        if (empty($referencedIds)) {
            $historyText = collect($history)->map(fn ($m) => $m['content'])->implode(' ');
            if (preg_match_all('/\d{4}_(?:IN|IS)_\d{4}/', $historyText, $historyMatches)) {
                $referencedIds = array_unique($historyMatches[0]);
            }
        }

        // NOW enrich with slash commands (has full history context)
        $contextService = app(\App\Services\Ai\ChatContextService::class);
        $searchEnriched = false;
        if ($slashCommand) {
            $commands = config('ai.chat_slash_commands', []);
            if (isset($commands[$slashCommand])) {
                $enriched = $contextService->enrichSlashCommand($slashCommand, $slashArgs, $referencedIds);
                $userMessage = $enriched['message'];
                if (! empty($enriched['extra_context'])) {
                    $userMessage .= $enriched['extra_context'];
                }
                $searchEnriched = ($slashCommand === 'search');
            }
        }

        // Detect inline web search intent (without /search command at message start)
        if (! $slashCommand && preg_match('/(?:\/search\b|\bsearch\s+(?:the\s+)?(?:web|internet|online)|look\s+up|check\s+online|\bsearch\s+for)\b/i', $userMessage)) {
            $searchContext = $contextService->getSearchContextFromMessage($userMessage, $referencedIds);
            if ($searchContext) {
                $userMessage .= "\n\n".$searchContext;
                $userMessage .= "\n\nThe user wants external web references combined with internal incident data. Always cite external sources using markdown links.";
                $searchEnriched = true;
            }
        }

        // Force web search when toggle is ON and not already enriched
        if ($request->boolean('web_search') && ! $searchEnriched && $slashCommand !== 'search') {
            $searchContext = $contextService->getSearchContextFromMessage($userMessage, $referencedIds);
            if ($searchContext) {
                $userMessage .= "\n\n".$searchContext;
                $userMessage .= "\n\nSupplementary web search results are included above. Integrate external references with internal data where relevant. Cite external sources using markdown links.";
                $searchEnriched = true;
            }
        }

        // Branch: persona mode vs default mode
        $personas = ! empty($personaKeys)
            ? WarRoomAgentConfig::whereIn('role_key', $personaKeys)->ordered()->get()
            : collect();

        if ($personas->isNotEmpty()) {
            return $this->handlePersonaMode(
                $conversation,
                $history,
                $userMessage,
                $model,
                $referencedIds,
                $personas,
                $isNewConversation,
                $request->input('message'),
                $contextService,
                $searchEnriched,
            );
        }

        // Default single-response mode with agentic tool calling
        $useTools = config('ai.tools.enabled', true);
        if ($useTools) {
            $result = app(AgenticChatService::class)->chatWithTools(
                $history, $userMessage, $model, $referencedIds
            );
        } else {
            $result = $this->chatService->chat($history, $userMessage, $model, logUsage: false, referencedIds: $referencedIds);
        }

        if (! $result->success) {
            return response()->json([
                'success' => false,
                'error' => $result->error,
                'conversation_id' => $conversation->id,
            ], 422);
        }

        // Extract follow-up questions from response
        $responseText = $result->text;
        $followUpQuestions = [];
        if (preg_match('/<!--FOLLOW_UP:(\[.*?\])-->/', $responseText, $followMatch)) {
            $decoded = json_decode($followMatch[1], true);
            if (is_array($decoded)) {
                $followUpQuestions = $decoded;
            }
            $responseText = trim(str_replace($followMatch[0], '', $responseText));
        }

        $toolCallsMade = property_exists($result, 'toolCallsMade') ? $result->toolCallsMade : [];

        $assistantMessage = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $responseText,
            'model' => $result->model,
            'tokens_used' => $result->totalTokens,
            'prompt_tokens' => $result->promptTokens,
            'completion_tokens' => $result->completionTokens,
            'web_search_used' => $searchEnriched,
            'tool_calls' => ! empty($toolCallsMade) ? $toolCallsMade : null,
            'tool_results' => ! empty($toolCallsMade) ? collect($toolCallsMade)->map(fn ($tc) => [
                'name' => $tc['name'],
                'result_length' => $tc['result_length'],
            ])->toArray() : null,
            'created_at' => now(),
        ]);

        $conversation->update(['updated_at' => now()]);

        $this->chatService->logChatUsage(
            $result->model ?? $model,
            $result,
            strlen($request->input('message')),
            (string) $assistantMessage->id,
        );

        $updatedTitle = null;
        if ($isNewConversation && $result->success) {
            $updatedTitle = $this->generateTitle($request->input('message'));
            if ($updatedTitle) {
                $conversation->update(['title' => $updatedTitle]);
            }
        }

        $freshness = $contextService->getDataFreshness();

        return response()->json([
            'success' => true,
            'conversation_id' => $conversation->id,
            'updated_title' => $updatedTitle,
            'follow_up_questions' => $followUpQuestions,
            'data_freshness' => $freshness,
            'web_search_used' => $searchEnriched,
            'tools_used' => ! empty($toolCallsMade) ? collect($toolCallsMade)->pluck('name')->unique()->values()->toArray() : [],
            'mode' => 'default',
            'user_message' => [
                'id' => (string) $conversation->messages()->where('role', 'user')->latest('created_at')->first()->id,
                'role' => 'user',
                'content' => $request->input('message'),
                'created_at' => now()->toIso8601String(),
            ],
            'assistant_message' => [
                'id' => (string) $assistantMessage->id,
                'role' => 'assistant',
                'content' => $responseText,
                'model' => $result->model,
                'tokens_used' => $result->totalTokens,
                'prompt_tokens' => $result->promptTokens,
                'completion_tokens' => $result->completionTokens,
                'follow_ups' => $followUpQuestions,
                'created_at' => now()->toIso8601String(),
            ],
        ]);
    }

    private function handlePersonaMode(
        ChatConversation $conversation,
        array $history,
        string $userMessage,
        ?string $model,
        array $referencedIds,
        $personas,
        bool $isNewConversation,
        string $rawMessage,
        \App\Services\Ai\ChatContextService $contextService,
        bool $searchEnriched = false,
    ): JsonResponse {
        $assistantMessages = [];
        $totalTokens = 0;
        $lastResult = null;

        foreach ($personas as $index => $persona) {
            $result = $this->chatService->chatWithPersona($history, $userMessage, $model, $persona, $referencedIds);

            if (! $result->success) {
                $assistantMessages[] = [
                    'id' => 'error-'.uniqid(),
                    'role' => 'assistant',
                    'content' => '⚠️ '.$result->error,
                    'model' => null,
                    'tokens_used' => null,
                    'prompt_tokens' => null,
                    'completion_tokens' => null,
                    'follow_ups' => null,
                    'created_at' => now()->toIso8601String(),
                    'persona' => [
                        'key' => $persona->role_key,
                        'name' => $persona->display_name,
                        'icon' => $persona->icon,
                        'color' => $persona->color,
                    ],
                ];

                continue;
            }

            $responseText = $result->text;
            $followUps = null;

            // Only the last persona gets follow-up questions
            if ($index === $personas->count() - 1) {
                if (preg_match('/<!--FOLLOW_UP:(\[.*?\])-->/', $responseText, $followMatch)) {
                    $decoded = json_decode($followMatch[1], true);
                    if (is_array($decoded)) {
                        $followUps = $decoded;
                    }
                    $responseText = trim(str_replace($followMatch[0], '', $responseText));
                }
            } else {
                // Strip any follow-up markers from non-last personas
                $responseText = preg_replace('/<!--FOLLOW_UP:\[.*?\]-->/', '', $responseText);
                $responseText = trim($responseText);
            }

            $assistantMessage = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $responseText,
                'model' => $result->model,
                'persona_key' => $persona->role_key,
                'persona_name' => $persona->display_name,
                'persona_icon' => $persona->icon,
                'persona_color' => $persona->color,
                'tokens_used' => $result->totalTokens,
                'prompt_tokens' => $result->promptTokens,
                'completion_tokens' => $result->completionTokens,
                'web_search_used' => $searchEnriched,
                'created_at' => now(),
            ]);

            $this->chatService->logChatUsage(
                $result->model ?? $model,
                $result,
                strlen($rawMessage),
                (string) $assistantMessage->id,
            );

            $totalTokens += $result->totalTokens ?? 0;
            $lastResult = $result;

            $assistantMessages[] = [
                'id' => (string) $assistantMessage->id,
                'role' => 'assistant',
                'content' => $responseText,
                'model' => $result->model,
                'tokens_used' => $result->totalTokens,
                'prompt_tokens' => $result->promptTokens,
                'completion_tokens' => $result->completionTokens,
                'follow_ups' => $followUps,
                'created_at' => now()->toIso8601String(),
                'persona' => [
                    'key' => $persona->role_key,
                    'name' => $persona->display_name,
                    'icon' => $persona->icon,
                    'color' => $persona->color,
                ],
            ];
        }

        $conversation->update(['updated_at' => now()]);

        $updatedTitle = null;
        if ($isNewConversation) {
            $updatedTitle = $this->generateTitle($rawMessage);
            if ($updatedTitle) {
                $conversation->update(['title' => $updatedTitle]);
            }
        }

        $freshness = $contextService->getDataFreshness();

        return response()->json([
            'success' => true,
            'conversation_id' => $conversation->id,
            'updated_title' => $updatedTitle,
            'data_freshness' => $freshness,
            'web_search_used' => $searchEnriched,
            'mode' => 'personas',
            'total_tokens_used' => $totalTokens,
            'user_message' => [
                'id' => (string) $conversation->messages()->where('role', 'user')->latest('created_at')->first()->id,
                'role' => 'user',
                'content' => $rawMessage,
                'created_at' => now()->toIso8601String(),
            ],
            'assistant_messages' => $assistantMessages,
        ]);
    }

    private function generateTitle(string $firstMessage): ?string
    {
        try {
            $baseUrl = rtrim(AiSetting::get('base_url', config('ai.base_url', '')), '/');
            $apiKey = AiSetting::get('api_key', config('ai.api_key', ''));
            $model = 'FAST-MODEL';

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout(10)
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => config('ai.chat_title_prompt')],
                        ['role' => 'user', 'content' => $firstMessage],
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
