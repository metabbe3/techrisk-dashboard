<?php

namespace App\Http\Controllers\Ai;

use App\Models\ChatMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatMessageFeedbackController
{
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'feedback' => 'required|string|in:positive,negative',
            'comment' => 'nullable|string|max:1000',
        ]);

        $message = ChatMessage::where('id', $id)
            ->where('role', 'assistant')
            ->whereHas('conversation', fn ($q) => $q->where('user_id', auth()->id()))
            ->firstOrFail();

        $message->update([
            'feedback' => $request->input('feedback'),
            'feedback_comment' => $request->input('comment'),
        ]);

        return response()->json([
            'success' => true,
            'feedback' => $message->feedback,
        ]);
    }
}
