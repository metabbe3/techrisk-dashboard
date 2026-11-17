<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\ConvertDocumentToMarkdown;
use App\Models\Incident;
use App\Models\InvestigationDocument;
use App\Services\EncryptionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Encrypts, stores and registers one investigation document on an incident.
 * Extracted from InvestigationDocumentsRelationManager's CreateAction — the
 * intake submission flow stores its generated DOCX/PDF through the same path.
 */
class StoreInvestigationDocument
{
    public function __construct(private readonly EncryptionService $encryptionService) {}

    public function handle(Incident $incident, string $bytes, string $filename, string $description): InvestigationDocument
    {
        $key = $this->encryptionService->generateKey();
        $salt = $this->encryptionService->generateSalt();
        $method = 'method'.rand(1, 3);
        $finalKey = $this->encryptionService->getFinalKey($key, $salt, $method);

        $path = 'investigation-forms/'.Str::uuid().'.encrypted';
        Storage::disk('public')->makeDirectory('investigation-forms');
        Storage::disk('public')->put($path, $this->encryptionService->encrypt($bytes, $finalKey));

        $document = $incident->investigationDocuments()->create([
            'description' => $description,
            'file_path' => $path,
            'original_filename' => $filename,
        ]);

        $document->encryptionKey()->create([
            'key' => $key,
            'salt' => $salt,
            'method' => $method,
        ]);

        try {
            $incident->audits()->create([
                'user_id' => Auth::id(),
                'event' => 'file_uploaded',
                'auditable_type' => Incident::class,
                'auditable_id' => $incident->id,
                'new_values' => [
                    'filename' => (string) Str::of($filename)->ascii(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating audit log: '.$e->getMessage());
        }

        ConvertDocumentToMarkdown::dispatch($document);

        return $document;
    }
}
