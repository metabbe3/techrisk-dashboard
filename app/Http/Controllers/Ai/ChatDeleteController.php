<?php

namespace App\Http\Controllers\Ai;

use App\Models\ChatConversation;
use Illuminate\Http\JsonResponse;

class ChatDeleteController
{
    public function __invoke(string $id): JsonResponse
    {
        $conversation = ChatConversation::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $conversation->delete();

        return response()->json(['success' => true]);
    }
}
