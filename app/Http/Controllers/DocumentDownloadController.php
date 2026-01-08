<?php

namespace App\Http\Controllers;

use App\Models\InvestigationDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

class DocumentDownloadController extends Controller
{
    public function download(InvestigationDocument $record)
    {
        // Optional: Add authorization check here.
        // For example, using a policy:
        // if (auth()->user()->cannot('download', $record)) {
        //     abort(403, 'Unauthorized');
        // }

        if (!$record->file_path || !Storage::disk('public')->exists($record->file_path)) {
            abort(404, 'File not found.');
        }

        try {
            $decryptedContent = Crypt::decryptString(Storage::disk('public')->get($record->file_path));
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            abort(500, 'Could not decrypt the file.');
        }
        
        return Response::streamDownload(function () use ($decryptedContent) {
            echo $decryptedContent;
        }, $record->original_filename);
    }
}