<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\InvestigationDocument;
use App\Services\EncryptionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Models\Audit;

class DownloadDocumentController extends Controller
{
    public function __invoke(InvestigationDocument $record, EncryptionService $encryptionService)
    {
        // Authorization check - only users with permission can download
        if (! auth()->check() || (! auth()->user()->can('manage incidents') && ! auth()->user()->can('view incidents'))) {
            abort(403, 'You do not have permission to download this file.');
        }

        // Additional check: user can only download if they're the PIC or have admin rights
        if (! auth()->user()->can('manage incidents') &&
            $record->incident->pic_id !== auth()->id()) {
            abort(403, 'You can only download documents from incidents you are assigned to.');
        }

        // 1. Check if file exists
        if (! $record->file_path || ! Storage::disk('public')->exists($record->file_path)) {
            abort(404, 'File not found on server.');
        }

        // 2. Get the encryption key

        $encryptionKey = $record->encryptionKey;

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

        // 5. Audit the download

        $record->incident->audits()->create([

            'user_id' => Auth::id(),

            'event' => 'file_downloaded',

            'auditable_type' => Incident::class,

            'auditable_id' => $record->incident->id,

            'new_values' => [

                'filename' => $record->original_filename,

            ],

        ]);

        // 6. Download the file with the original name

        return response()->streamDownload(function () use ($decryptedContent) {

            echo $decryptedContent;

        }, $record->original_filename);

    }
}
