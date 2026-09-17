<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\JsonResponse;

/**
 * Server-side branch truncation for edit-and-resend / regenerate: deletes the
 * target message and everything after it. The client used to slice only its
 * local array — the server's 20-message history window kept feeding the model
 * the stale branch, so the regenerated answer saw its own previous reply.
 */
class ChatMessageTruncateController extends Controller
{
    public function __invoke(string $conversationId, string $messageId): JsonResponse
    {
        $conversation = ChatConversation::where('id', $conversationId)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $message = ChatMessage::where('id', $messageId)
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();

        // ponytail: created_at is second-precision; a message landing in the same
        // second as the target is deleted too. Practically unreachable (stream
        // replies take seconds), ordering id tie-break would need a cursor column.
        $deleted = ChatMessage::where('conversation_id', $conversation->id)
            ->where('created_at', '>=', $message->created_at)
            ->delete();

        return $this->successResponse(['success' => true, 'deleted' => $deleted]);
    }
}
