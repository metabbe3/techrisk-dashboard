<?php

namespace App\Services\Ai;

use App\Services\Markdown\DocumentConverterService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatAttachmentService
{
    private const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    private const DOCUMENT_EXTENSIONS = ['pdf', 'docx', 'xlsx', 'txt', 'md', 'csv', 'json'];

    private const TEXT_EXTENSIONS = ['txt', 'md', 'csv', 'json'];

    private const MAX_IMAGE_SIZE = 5 * 1024 * 1024; // 5MB

    private const MAX_DOCUMENT_SIZE = 15 * 1024 * 1024; // 15MB

    public function __construct(
        private DocumentConverterService $converter,
    ) {}

    public function storeAttachment(UploadedFile $file): array
    {
        $mime = $file->getMimeType();
        $ext = strtolower($file->getClientOriginalExtension());
        $id = (string) Str::uuid();
        $isImage = in_array($mime, self::IMAGE_TYPES);
        $isDocument = in_array($ext, self::DOCUMENT_EXTENSIONS);
        $isText = in_array($ext, self::TEXT_EXTENSIONS);

        if ($ext === 'doc') {
            // Legacy .doc can't be parsed by PhpWord — reject loudly instead of
            // storing a file the AI silently can't read.
            throw new \InvalidArgumentException('.doc files are not supported. Please convert to .docx or PDF.');
        }

        if (! $isImage && ! $isDocument) {
            throw new \InvalidArgumentException('Unsupported file type. Allowed: images (PNG, JPG, GIF, WebP) and documents (PDF, DOCX, XLSX, TXT, MD, CSV, JSON).');
        }

        // Content sniffing: the extension must match what the bytes actually are,
        // so a text file renamed to .pdf (or vice versa) is rejected rather than
        // handed to the wrong parser.
        if ($isDocument && ! $this->matchesExtension($mime, $ext)) {
            throw new \InvalidArgumentException("File content does not match its .{$ext} extension. Please upload the original file.");
        }

        $maxSize = $isImage ? self::MAX_IMAGE_SIZE : self::MAX_DOCUMENT_SIZE;
        if ($file->getSize() > $maxSize) {
            $maxMb = $isImage ? 5 : 15;
            throw new \InvalidArgumentException("File too large. Maximum size for {$ext} files is {$maxMb}MB.");
        }

        $path = $file->storeAs('chat-attachments', "{$id}.{$ext}", 'local');
        $filename = $file->getClientOriginalName();

        $result = [
            'id' => $id,
            'type' => $isImage ? 'image' : 'document',
            'filename' => $filename,
            'mime_type' => $mime,
            'size' => $file->getSize(),
            'path' => $path,
        ];

        if ($isDocument) {
            if ($isText) {
                // Plain-text formats need no conversion — the content IS the extraction.
                $result['markdown'] = $file->getContent();
            } else {
                $result['markdown'] = $this->convertDocument($path, $ext);
            }
            $markdownPath = "chat-attachments/{$id}.md";
            Storage::disk('local')->put($markdownPath, $result['markdown'] ?? '');
        }

        return $result;
    }

    /**
     * finfo-based sanity check: does the detected MIME type plausibly match the
     * claimed extension? Text formats are lenient (finfo reports several variants);
     * binary formats must match exactly.
     */
    private function matchesExtension(string $mime, string $ext): bool
    {
        return match ($ext) {
            'pdf' => $mime === 'application/pdf',
            // libmagic identifies OOXML by its zip content (officeDocument mime),
            // not as generic application/zip — accept both spellings.
            'docx' => in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true),
            'xlsx' => in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true),
            'txt', 'md', 'csv' => str_starts_with($mime, 'text/'),
            'json' => str_starts_with($mime, 'text/') || $mime === 'application/json',
            default => true,
        };
    }

    public function buildMessageContent(string $userMessage, array $attachments): string|array
    {
        if (empty($attachments)) {
            return $userMessage;
        }

        $parts = [['type' => 'text', 'text' => $userMessage]];

        foreach ($attachments as $attachment) {
            if (($attachment['type'] ?? '') === 'image') {
                $path = $attachment['path'] ?? $this->getAttachmentUrl($attachment['id']);
                $imageData = $path ? $this->getBase64Image($path) : null;
                if ($imageData) {
                    $parts[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$attachment['mime_type']};base64,{$imageData}",
                        ],
                    ];
                }
            } elseif (($attachment['type'] ?? '') === 'document') {
                $markdown = $this->getDocumentMarkdown($attachment['id']);
                if ($markdown) {
                    $parts[] = [
                        'type' => 'text',
                        'text' => "---\nAttached Document: {$attachment['filename']}\n---\n{$markdown}",
                    ];
                }
            }
        }

        return count($parts) > 1 ? $parts : $userMessage;
    }

    public function getAttachmentMetadata(array $attachments): array
    {
        return array_map(function ($a) {
            return [
                'id' => $a['id'],
                'type' => $a['type'],
                'filename' => $a['filename'],
                'mime_type' => $a['mime_type'] ?? null,
                'size' => $a['size'] ?? null,
            ];
        }, $attachments);
    }

    /**
     * Re-inject extracted document content into prior history turns so follow-up
     * questions still see files sent earlier (they'd otherwise vanish — history
     * is built from message content only). Walks newest → oldest within a
     * turn/token budget; each document is capped per ai.attachments.history_char_cap.
     *
     * The NEWEST user message is excluded — buildMessageContent() already expands
     * its attachments (multimodal parts). Prior-turn images are skipped: re-sending
     * N base64 images would blow the context window for little value.
     */
    public function expandHistory(array $history): array
    {
        $maxTurns = (int) config('ai.attachments.history_turns', 5);
        $tokenBudget = (int) config('ai.attachments.history_token_budget', 6000);
        $charCap = (int) config('ai.attachments.history_char_cap', 8000);

        $newestUserIdx = null;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? null) === 'user') {
                $newestUserIdx = $i;
                break;
            }
        }

        $turnsSeen = 0;
        $tokensUsed = 0;

        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($i === $newestUserIdx) {
                continue;
            }
            $attachments = $history[$i]['attachments'] ?? [];
            if (empty($attachments)) {
                continue;
            }
            if ($turnsSeen >= $maxTurns || $tokensUsed >= $tokenBudget) {
                break;
            }
            $turnsSeen++;

            foreach ($attachments as $attachment) {
                if (($attachment['type'] ?? '') !== 'document') {
                    continue;
                }
                $markdown = $this->getDocumentMarkdown($attachment['id'] ?? '');
                if (! $markdown || trim($markdown) === '') {
                    continue;
                }
                if (strlen($markdown) > $charCap) {
                    $markdown = substr($markdown, 0, $charCap)."\n[…truncated…]";
                }
                $block = "\n\n---\nAttached Document: {$attachment['filename']}\n---\n{$markdown}";
                $history[$i]['content'] .= $block;
                $tokensUsed += TokenEstimator::estimate($block);
            }
        }

        // Strip the helper key — apiMessages must stay {role, content}.
        foreach ($history as $i => $entry) {
            unset($history[$i]['attachments']);
        }

        return $history;
    }

    public function getAttachmentUrl(string $id): ?string
    {
        // ponytail: O(n) directory scan — fine for one flat directory; index if it exceeds ~10k files.
        $files = Storage::disk('local')->files('chat-attachments');

        // Prefer the original binary over the converted {uuid}.md sidecar — the
        // sorted listing returns .md first for document uploads, which used to
        // serve the markdown extraction instead of the actual file.
        return collect($files)
            ->first(fn ($f) => Str::startsWith(basename($f), $id.'.') && ! str_ends_with($f, '.md'))
            ?? collect($files)->first(fn ($f) => Str::startsWith(basename($f), $id.'.'));
    }

    public function cleanupOldAttachments(): int
    {
        $cutoff = now()->subDay()->timestamp;
        $deleted = 0;

        $files = Storage::disk('local')->files('chat-attachments');
        foreach ($files as $file) {
            if (Storage::disk('local')->lastModified($file) < $cutoff) {
                Storage::disk('local')->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function convertDocument(string $path, string $extension): ?string
    {
        try {
            $content = Storage::disk('local')->get($path);

            return $this->converter->convertRaw($content, $extension);
        } catch (\Throwable $e) {
            Log::warning('Failed to convert chat attachment document', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function getBase64Image(string $path): ?string
    {
        try {
            $content = Storage::disk('local')->get($path);

            return base64_encode($content);
        } catch (\Throwable $e) {
            Log::warning('Failed to read chat attachment image', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function getDocumentMarkdown(string $id): ?string
    {
        $markdownPath = "chat-attachments/{$id}.md";

        try {
            if (Storage::disk('local')->exists($markdownPath)) {
                return Storage::disk('local')->get($markdownPath);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to read chat attachment markdown', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
