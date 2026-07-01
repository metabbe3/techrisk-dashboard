<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Support\Export;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Parsedown;

class ChatExportPdfController extends Controller
{
    public function __invoke(Request $request, string $id)
    {
        $conversation = ChatConversation::with(['messages', 'user:id,name'])
            ->forUser()
            ->findOrFail($id);

        $messages = $conversation->messages;

        if ($messages->isEmpty()) {
            return $this->errorResponse('No messages to export.', 422);
        }

        $format = $request->input('format', 'pdf');

        if ($format === 'json') {
            return $this->exportJson($conversation, $messages);
        }

        if ($format === 'markdown') {
            return $this->exportMarkdown($conversation, $messages);
        }

        return $this->exportPdf($conversation, $messages);
    }

    private function exportJson(ChatConversation $conversation, $messages): \Illuminate\Http\JsonResponse
    {
        $titleSlug = \Illuminate\Support\Str::slug($conversation->title ?? 'chat');

        return response()->json([
            'conversation' => [
                'id' => (string) $conversation->id,
                'title' => $conversation->title,
                'model' => $conversation->model,
                'created_at' => $conversation->created_at?->toIso8601String(),
                'exported_at' => now()->toIso8601String(),
            ],
            'messages' => $messages->map(fn ($msg) => [
                'id' => (string) $msg->id,
                'role' => $msg->role,
                'content' => $msg->content,
                'model' => $msg->model,
                'persona' => $msg->persona_key ? [
                    'key' => $msg->persona_key,
                    'name' => $msg->persona_name,
                    'icon' => $msg->persona_icon,
                    'color' => $msg->persona_color,
                ] : null,
                'tokens_used' => $msg->tokens_used,
                'prompt_tokens' => $msg->prompt_tokens,
                'completion_tokens' => $msg->completion_tokens,
                'web_search_used' => $msg->web_search_used,
                'feedback' => $msg->feedback,
                'created_at' => $msg->created_at?->toIso8601String(),
            ]),
            'total_tokens' => $messages->sum('tokens_used'),
        ])->header('Content-Disposition', 'attachment; filename="'.Export::downloadFilename('techrisk-ai-'.$titleSlug, 'json', now()->format('Y-m-d')).'"');
    }

    private function exportMarkdown(ChatConversation $conversation, $messages): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $title = $conversation->title ?? 'TechRisk AI Chat';
        $lines = [
            '# '.$title,
            '',
            '> Exported: '.now()->format('d M Y, H:i').' | Model: '.($conversation->model ?? 'N/A').' | Tokens: '.$messages->sum('tokens_used'),
            '',
            '---',
            '',
        ];

        foreach ($messages as $msg) {
            $label = $msg->role === 'user'
                ? '**User**'
                : '**'.($msg->persona_name ?? 'TechRisk AI').'**';

            if ($msg->model) {
                $label .= ' `'.$msg->model.'`';
            }

            $lines[] = '### '.$label;
            $lines[] = '';
            $lines[] = $msg->content;
            $lines[] = '';
            $lines[] = '---';
            $lines[] = '';
        }

        $markdown = implode("\n", $lines);
        $titleSlug = \Illuminate\Support\Str::slug($conversation->title ?? 'chat');

        return response()->streamDownload(function () use ($markdown) {
            echo $markdown;
        }, Export::downloadFilename('techrisk-ai-'.$titleSlug, 'md', now()->format('Y-m-d')), [
            'Content-Type' => 'text/markdown; charset=utf-8',
        ]);
    }

    private function exportPdf(ChatConversation $conversation, $messages)
    {
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
        $filename = Export::downloadFilename('techrisk-ai-'.$titleSlug, 'pdf', now()->format('Y-m-d'));

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
