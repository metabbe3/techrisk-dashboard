<?php

namespace App\Http\Controllers\Ai;

use App\Services\Ai\ChatAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ChatAttachmentController
{
    public function upload(Request $request, ChatAttachmentService $service): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:15360',
        ]);

        try {
            $attachment = $service->storeAttachment($request->file('file'));

            return response()->json([
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
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to process attachment. Please try again.',
            ], 500);
        }
    }

    public function show(string $id, ChatAttachmentService $service)
    {
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
