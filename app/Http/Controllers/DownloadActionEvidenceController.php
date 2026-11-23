<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActionImprovementEvidence;
use App\Models\Incident;
use App\Services\EncryptionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class DownloadActionEvidenceController extends Controller
{
    public function __invoke(ActionImprovementEvidence $record, EncryptionService $encryptionService)
    {
        // Gate routes through ActionImprovementPolicy so policy changes
        // propagate (owner record = the action improvement, not the incident).
        if (! auth()->user()?->can('view', $record->actionImprovement)) {
            abort(403, 'You do not have permission to download this file.');
        }

        // Record-level gate — the ONE scope (red-team fix W2): permission-only
        // access let any team-viewer download another team's decrypted proof.
        // Same semantics as IncidentResource::canView: the parent incident
        // must be inside the caller's applyUserYearAccess slice.
        $incident = $record->actionImprovement->incident;
        if (! Incident::query()
            ->applyUserYearAccess(auth()->user())
            ->whereKey($incident->getKey())
            ->exists()) {
            abort(403, 'You do not have permission to download this file.');
        }

        // Link proof (chat / Notion) — nothing to decrypt; BOTH gates ran
        // FIRST so unauthorized users can't ride the redirect. Only https
        // links redirect (red-team fix W4: the route must not become an open
        // redirect on the real domain — non-https/scheme-less URLs abort).
        if ($record->isLink()) {
            $parsed = parse_url($record->link_url ?? '');
            if (strtolower((string) ($parsed['scheme'] ?? '')) !== 'https' || empty($parsed['host'])) {
                abort(404, 'This proof link is not a valid https URL.');
            }

            return redirect()->away($record->link_url);
        }

        // 1. Check if file exists
        if (! $record->file_path || ! Storage::disk('public')->exists($record->file_path)) {
            abort(404, 'File not found on server.');
        }

        // 2. Get the encryption key
        $encryptionKey = $record->evidenceKey;

        if (! $encryptionKey) {
            abort(500, 'Encryption key not found for this file.');
        }

        // 3. Get the encrypted content
        $encryptedContent = Storage::disk('public')->get($record->file_path);

        // 4. Decrypt the content
        try {
            $finalKey = $encryptionService->getFinalKey($encryptionKey->key, $encryptionKey->salt, $encryptionKey->method);
            $decryptedContent = $encryptionService->decrypt($encryptedContent, $finalKey);
        } catch (\Exception $e) {
            abort(500, 'Could not decrypt the file. It might be corrupted or the key changed.');
        }

        // 5. Audit the download on the parent incident
        $record->actionImprovement->incident->audits()->create([
            'user_id' => Auth::id(),
            'event' => 'file_downloaded',
            'auditable_type' => Incident::class,
            'auditable_id' => $record->actionImprovement->incident->id,
            'new_values' => [
                'filename' => $record->original_filename,
            ],
        ]);

        // 6. Download the file with the original name
        $extension = pathinfo($record->original_filename, PATHINFO_EXTENSION);
        $mimeType = \Symfony\Component\Mime\MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? 'application/octet-stream';

        return response()->streamDownload(function () use ($decryptedContent) {
            echo $decryptedContent;
        }, $record->original_filename, ['Content-Type' => $mimeType]);
    }
}
