<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use Illuminate\Http\JsonResponse;

class ChatDeleteController extends Controller
{
    public function __invoke(string $id): JsonResponse
    {
        $conversation = ChatConversation::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $conversation->delete();

        return $this->successResponse(['success' => true]);
    }
}
