<?php

namespace App\Http\Controllers\Ai;

use App\Models\ChatConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatListController
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = ChatConversation::where('user_id', auth()->id());

        $search = $request->input('search');
        if ($search && mb_strlen($search) >= 2) {
            $query->where(fn ($q) => $q
                ->where('title', 'LIKE', "%{$search}%")
                ->orWhereHas('messages', fn ($mq) => $mq->where('content', 'LIKE', "%{$search}%"))
            );
        }

        $conversations = $query
            ->with('latestMessage')
            ->latestFirst()
            ->take(50)
            ->get()
            ->map(fn ($c) => [
                'id' => (string) $c->id,
                'title' => $c->title,
                'model' => $c->model,
                'updated_at' => $c->updated_at?->toIso8601String(),
                'created_at' => $c->created_at?->toIso8601String(),
                'last_message' => $c->latestMessage ? mb_substr($c->latestMessage->content, 0, 100) : null,
            ]);

        return response()->json(['conversations' => $conversations]);
    }
}
