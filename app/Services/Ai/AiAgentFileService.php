<?php

declare(strict_types=1);
namespace App\Services\Ai;

use App\Models\AiAgent;
use App\Models\AiAgentFile;
use App\Services\Markdown\DocumentConverterService;
use Illuminate\Http\UploadedFile;

/**
 * Attaches reference files to an agent: validated upload, local storage,
 * text extraction at attach time (runs never pay extraction cost).
 * Same whitelist + content-sniffing as AI chat attachments.
 */
class AiAgentFileService
{
    private const ALLOWED = ['pdf', 'docx', 'xlsx', 'txt', 'md', 'csv', 'json'];

    private const MAX_BYTES = 15360 * 1024; // 15MB, matches chat attachments

    public function __construct(private DocumentConverterService $converter) {}

    public function attach(AiAgent $agent, UploadedFile $file): AiAgentFile
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED, true)) {
            throw new \InvalidArgumentException("File type .{$extension} is not allowed. Allowed: ".implode(', ', self::ALLOWED).'.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('File exceeds the 15MB limit.');
        }

        // Content-sniff: the real MIME type must match the extension claim.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = (string) $finfo->buffer($file->getContent());

        if (! $this->matchesExtension($detected, $extension)) {
            throw new \InvalidArgumentException('File content does not match its extension.');
        }

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $path = $file->storeAs('agent-files', "{$uuid}.{$extension}", 'local');
        $content = $file->getContent();

        $extracted = match ($extension) {
            'txt', 'md', 'csv', 'json' => $content,
            default => $this->converter->convertRaw($content, $extension),
        };

        return AiAgentFile::create([
            'agent_id' => $agent->id,
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $detected,
            'size' => strlen($content),
            'extracted_text' => blank($extracted) ? null : $extracted,
        ]);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array{attached: AiAgentFile[], errors: string[]}
     */
    public function attachMany(AiAgent $agent, array $files): array
    {
        $attached = [];
        $errors = [];

        foreach ($files as $file) {
            try {
                $attached[] = $this->attach($agent, $file);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $file->getClientOriginalName().': '.$e->getMessage();
            }
        }

        return ['attached' => $attached, 'errors' => $errors];
    }

    private function matchesExtension(string $detectedMime, string $extension): bool
    {
        return match ($extension) {
            'pdf' => $detectedMime === 'application/pdf',
            'docx' => str_contains($detectedMime, 'officedocument.wordprocessingml'),
            'xlsx' => str_contains($detectedMime, 'officedocument.spreadsheetml') || str_contains($detectedMime, 'zip'),
            'txt', 'md', 'csv' => str_starts_with($detectedMime, 'text/') || $detectedMime === 'application/csv' || str_contains($detectedMime, 'ascii') || $detectedMime === 'application/octet-stream',
            'json' => str_contains($detectedMime, 'json') || str_starts_with($detectedMime, 'text/'),
            default => false,
        };
    }
}
