<?php

namespace App\Http\Controllers\Ai;

use App\Models\ChatConversation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Parsedown;

class ChatExportPdfController
{
    public function __invoke(Request $request, string $id)
    {
        $conversation = ChatConversation::with(['messages', 'user:id,name'])
            ->forUser()
            ->findOrFail($id);

        $messages = $conversation->messages;

        if ($messages->isEmpty()) {
            return response()->json(['message' => 'No messages to export.'], 422);
        }

        $parsedown = new Parsedown;

        $messages = $messages->map(function ($msg) use ($parsedown) {
            if ($msg->role === 'assistant') {
                $msg->parsed_html = $parsedown->text($msg->content);
            }

            return $msg;
        });

        $totalTokens = $messages->sum('tokens_used');
        $personasUsed = $messages
            ->where('role', 'assistant')
            ->whereNotNull('persona_name')
            ->pluck('persona_name')
            ->unique()
            ->values()
            ->toArray();

        $titleSlug = \Illuminate\Support\Str::slug($conversation->title ?? 'chat');
        $filename = "techrisk-ai-{$titleSlug}-".now()->format('Y-m-d').'.pdf';

        $pdf = Pdf::loadView('ai-chat.pdf-export', [
            'title' => $conversation->title ?? 'TechRisk AI Chat',
            'messages' => $messages,
            'user_name' => $conversation->user?->name,
            'model' => $conversation->model,
            'total_tokens' => $totalTokens,
            'exported_at' => now()->format('d M Y, H:i'),
            'personas_used' => $personasUsed,
        ]);

        return $pdf->download($filename);
    }
}
