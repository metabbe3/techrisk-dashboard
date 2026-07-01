<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatConversationUpdateController extends Controller
{
    public function pin(Request $request, string $id): JsonResponse
    {
        $conversation = ChatConversation::forUser()->findOrFail($id);
        $conversation->update([
            'pinned_at' => $conversation->isPinned() ? null : now(),
        ]);

        return $this->successResponse([
            'success' => true,
            'pinned' => $conversation->isPinned(),
        ]);
    }

    public function updateFolder(Request $request, string $id): JsonResponse
    {
        $request->validate(['folder' => 'nullable|string|max:50']);
        $conversation = ChatConversation::forUser()->findOrFail($id);
        $conversation->update(['folder' => $request->input('folder') ?: null]);

        return $this->successResponse(['success' => true, 'folder' => $conversation->folder]);
    }

    public function updateTags(Request $request, string $id): JsonResponse
    {
        $request->validate(['tags' => 'nullable|array', 'tags.*' => 'string|max:30']);
        $conversation = ChatConversation::forUser()->findOrFail($id);
        $conversation->update(['tags' => $request->input('tags') ?: null]);

        return $this->successResponse(['success' => true, 'tags' => $conversation->tags]);
    }

    public function updateTitle(Request $request, string $id): JsonResponse
    {
        $request->validate(['title' => 'required|string|max:80']);
        $conversation = ChatConversation::forUser()->findOrFail($id);
        $conversation->update(['title' => strip_tags($request->input('title'))]);

        return $this->successResponse(['success' => true, 'title' => $conversation->title]);
    }

    public function folders(): JsonResponse
    {
        return $this->successResponse([
            'folders' => ChatConversation::getFolders(),
        ]);
    }
}
