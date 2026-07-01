<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatCreateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $conversation = ChatConversation::create([
            'user_id' => auth()->id(),
            'title' => $request->input('title', 'New Chat'),
            'model' => $request->input('model'),
        ]);

        return $this->successResponse([
            'id' => (string) $conversation->id,
            'title' => $conversation->title,
            'created_at' => $conversation->created_at->toIso8601String(),
        ]);
    }
}
