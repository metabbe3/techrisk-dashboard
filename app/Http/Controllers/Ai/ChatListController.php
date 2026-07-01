<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatListController extends Controller
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

        if ($request->filled('folder')) {
            $query->inFolder($request->input('folder'));
        }

        if ($request->boolean('pinned')) {
            $query->pinned();
        }

        if ($request->filled('tag')) {
            $query->withTag($request->input('tag'));
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
                'folder' => $c->folder,
                'tags' => $c->tags,
                'pinned' => $c->isPinned(),
                'updated_at' => $c->updated_at?->toIso8601String(),
                'created_at' => $c->created_at?->toIso8601String(),
                'last_message' => $c->latestMessage ? mb_substr($c->latestMessage->content, 0, 100) : null,
            ]);

        $folders = ChatConversation::getFolders();

        return $this->successResponse([
            'conversations' => $conversations,
            'folders' => $folders,
        ]);
    }
}
