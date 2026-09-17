<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Services\Ai\ChatAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ChatAttachmentController extends Controller
{
    public function upload(Request $request, ChatAttachmentService $service): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:15360',
        ]);

        try {
            $attachment = $service->storeAttachment($request->file('file'));

            return $this->successResponse([
                'success' => true,
                'attachment' => [
                    'id' => $attachment['id'],
                    'type' => $attachment['type'],
                    'filename' => $attachment['filename'],
                    'mime_type' => $attachment['mime_type'],
                    'size' => $attachment['size'],
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->errorResponse('Failed to process attachment. Please try again.', 500);
        }
    }

    public function show(string $id, ChatAttachmentService $service)
    {
        // Ownership: the uuid must appear in a message belonging to one of the
        // authenticated user's own conversations. No attachments table exists —
        // the attachments JSON on chat_messages is the link. Unknown/foreign
        // uuids are indistinguishable (404, not 403) so ids can't be probed.
        $owns = ChatMessage::query()
            ->where('attachments', 'like', '%'.$id.'%')
            ->whereHas('conversation', fn ($q) => $q->where('user_id', auth()->id()))
            ->exists();

        if (! $owns) {
            abort(404);
        }

        $path = $service->getAttachmentUrl($id);

        if (! $path || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $mime = mime_content_type(Storage::disk('local')->path($path));

        return Storage::disk('local')->response($path, basename($path), [
            'Content-Type' => $mime,
        ]);
    }
}
