<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use Illuminate\Http\JsonResponse;

class ChatMessagesController extends Controller
{
    public function __invoke(string $id): JsonResponse
    {
        $conversation = ChatConversation::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'id' => (string) $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'model' => $m->model,
                'tokens_used' => $m->tokens_used,
                'prompt_tokens' => $m->prompt_tokens,
                'completion_tokens' => $m->completion_tokens,
                'feedback' => $m->feedback,
                'created_at' => $m->created_at?->toIso8601String(),
                'attachments' => $m->attachments,
                'is_plan_message' => $m->is_plan_message,
                'plan_role' => $m->plan_role,
                'plan_metadata' => $m->plan_metadata,
                'persona' => $m->persona_key ? [
                    'key' => $m->persona_key,
                    'name' => $m->persona_name,
                    'icon' => $m->persona_icon,
                    'color' => $m->persona_color,
                ] : null,
            ]);

        return $this->successResponse([
            'conversation' => [
                'id' => (string) $conversation->id,
                'title' => $conversation->title,
                'model' => $conversation->model,
            ],
            'messages' => $messages,
        ]);
    }
}
