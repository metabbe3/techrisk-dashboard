<?php

namespace App\Http\Controllers\Ai;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Ai\AiChatService;
use App\Services\Ai\AiTextResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatFinalizeController
{
    public function __construct(
        private AiChatService $chatService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'conversation_id' => 'required|uuid',
            'content' => 'required|string',
            'model' => 'required|string',
            'prompt_tokens' => 'nullable|integer',
            'completion_tokens' => 'nullable|integer',
            'total_tokens' => 'nullable|integer',
            'response_time_ms' => 'nullable|numeric',
            'is_new' => 'nullable|boolean',
            'first_message' => 'nullable|string',
        ]);

        $conversation = ChatConversation::where('id', $request->conversation_id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        // Extract follow-up questions
        $responseText = $request->input('content');
        $followUpQuestions = [];
        if (preg_match('/<!--FOLLOW_UP:(\[.*?\])-->/', $responseText, $followMatch)) {
            $decoded = json_decode($followMatch[1], true);
            if (is_array($decoded)) {
                $followUpQuestions = $decoded;
            }
            $responseText = trim(str_replace($followMatch[0], '', $responseText));
        }

        $assistantMessage = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $responseText,
            'model' => $request->input('model'),
            'tokens_used' => $request->input('total_tokens'),
            'prompt_tokens' => $request->input('prompt_tokens'),
            'completion_tokens' => $request->input('completion_tokens'),
            'created_at' => now(),
        ]);

        $conversation->update(['updated_at' => now()]);

        // Log usage
        $result = new AiTextResult(
            success: true,
            text: $responseText,
            model: $request->input('model'),
            promptTokens: $request->input('prompt_tokens'),
            completionTokens: $request->input('completion_tokens'),
            totalTokens: $request->input('total_tokens'),
            responseTimeMs: $request->input('response_time_ms'),
        );
        $this->chatService->logChatUsage(
            $request->input('model'),
            $result,
            strlen($request->input('first_message', '')),
            (string) $assistantMessage->id,
        );

        // Generate title for new conversations
        $updatedTitle = null;
        if ($request->boolean('is_new') && $request->input('first_message')) {
            $updatedTitle = $this->chatService->generateTitle($request->input('first_message'), $responseText);
            if ($updatedTitle) {
                $conversation->update(['title' => $updatedTitle]);
            }
        }

        return response()->json([
            'success' => true,
            'conversation_id' => (string) $conversation->id,
            'updated_title' => $updatedTitle,
            'follow_up_questions' => $followUpQuestions,
            'assistant_message' => [
                'id' => (string) $assistantMessage->id,
                'role' => 'assistant',
                'content' => $responseText,
                'model' => $request->input('model'),
                'tokens_used' => $request->input('total_tokens'),
                'prompt_tokens' => $request->input('prompt_tokens'),
                'completion_tokens' => $request->input('completion_tokens'),
                'follow_ups' => $followUpQuestions,
                'created_at' => now()->toIso8601String(),
            ],
        ]);
    }

}
