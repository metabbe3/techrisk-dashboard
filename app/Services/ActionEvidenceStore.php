<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActionImprovement;
use App\Models\ActionImprovementEvidence;
use App\Models\Incident;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Encrypt-on-upload an evidence file onto an action improvement (team-viewer
 * feature, owner 2026-11-20). ONE home — the EvidenceRelationManager (edit
 * page) and the shared Submit Proof action (ActionImprovementFormSchema) both
 * route here so the key/salt/audit flow cannot drift. File events audit on
 * the parent Incident so they surface in the incident's audit trail
 * (supporting-docs precedent).
 */
class ActionEvidenceStore
{
    public function __construct(private EncryptionService $encryptionService) {}

    /**
     * @param  bool  $pendingApproval  true = the row carries submitted_by and
     *                                 waits on an Approve Proof stamp (the
     *                                 Submit Proof action's worker branch);
     *                                 false = plain upload, never pending.
     * @return ActionImprovementEvidence the created evidence record
     */
    public function store(ActionImprovement $action, ?UploadedFile $file, ?int $userId, bool $pendingApproval = false): ActionImprovementEvidence
    {
        if (! $file instanceof UploadedFile) {
            throw new \InvalidArgumentException('An evidence file is required.');
        }

        $key = $this->encryptionService->generateKey();
        $salt = $this->encryptionService->generateSalt();
        $method = 'method'.rand(1, 3);
        $finalKey = $this->encryptionService->getFinalKey($key, $salt, $method);

        $directory = 'action-evidence';
        $path = $directory.'/'.Str::uuid().'.encrypted';

        Storage::disk('public')->makeDirectory($directory);
        Storage::disk('public')->put($path, $this->encryptionService->encrypt($file->get(), $finalKey));

        $originalFilename = $file->getClientOriginalName();

        $evidence = $action->evidence()->create([
            'file_path' => $path,
            'original_filename' => $originalFilename,
            'submitted_by' => $pendingApproval ? $userId : null,
        ]);
        $evidence->evidenceKey()->create([
            'key' => $key,
            'salt' => $salt,
            'method' => $method,
        ]);

        try {
            $action->incident->audits()->create([
                'user_id' => $userId,
                'event' => 'file_uploaded',
                'auditable_type' => Incident::class,
                'auditable_id' => $action->incident->id,
                'new_values' => [
                    'filename' => (string) Str::of($originalFilename)->ascii(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating audit log: '.$e->getMessage());
        }

        return $evidence;
    }

    /**
     * Link proof (chat / Notion URL) — no file, no encryption, no key row.
     * A file+link submission creates TWO evidence rows (one per method); the
     * Approve Proof action stamps ALL pending rows, so that composes.
     */
    public function storeLink(ActionImprovement $action, string $url, ?int $userId, bool $pendingApproval = false): ActionImprovementEvidence
    {
        $evidence = $action->evidence()->create([
            'link_url' => $url,
            'submitted_by' => $pendingApproval ? $userId : null,
        ]);

        try {
            $action->incident->audits()->create([
                'user_id' => $userId,
                'event' => 'link_submitted',
                'auditable_type' => Incident::class,
                'auditable_id' => $action->incident->id,
                'new_values' => [
                    'url' => $url,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating audit log: '.$e->getMessage());
        }

        return $evidence;
    }
}
