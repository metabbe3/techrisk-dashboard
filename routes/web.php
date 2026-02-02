<?php

use App\Http\Controllers\DownloadDocumentController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin/login');

// Add this line
Route::get('/documents/{record}/download', DownloadDocumentController::class)
    ->middleware(['auth']) // Optional: Ensure only logged-in users can download
    ->name('documents.download');
