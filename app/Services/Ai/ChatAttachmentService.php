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

    private const DOCUMENT_EXTENSIONS = ['pdf', 'docx', 'doc'];

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

        if (! $isImage && ! $isDocument) {
            throw new \InvalidArgumentException('Unsupported file type. Allowed: images (PNG, JPG, GIF, WebP) and documents (PDF, DOCX, DOC).');
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
            $result['markdown'] = $this->convertDocument($path, $ext);
            $markdownPath = "chat-attachments/{$id}.md";
            Storage::disk('local')->put($markdownPath, $result['markdown'] ?? '');
        }

        return $result;
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

    public function getAttachmentUrl(string $id): ?string
    {
        $files = Storage::disk('local')->files('chat-attachments');
        $match = collect($files)->first(fn ($f) => Str::startsWith(basename($f), $id.'.'));

        return $match;
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
