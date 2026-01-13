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
        // Authorization check - only users with permission can download
        if (!auth()->check() || (!auth()->user()->can('manage incidents') && !auth()->user()->can('view incidents'))) {
            abort(403, 'You do not have permission to download this file.');
        }

        // Additional check: user can only download if they're the PIC or have admin rights
        if (!auth()->user()->can('manage incidents') &&
            $record->incident->pic_id !== auth()->id()) {
            abort(403, 'You can only download documents from incidents you are assigned to.');
        }

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