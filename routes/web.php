<?php

use App\Http\Controllers\DownloadDocumentController;
use App\Livewire\AccessRequestForm;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin/login');

// Access request form (public - uses standalone Livewire component)
Route::get('/request-access', AccessRequestForm::class)->name('request-access');

// Add this line
Route::get('/documents/{record}/download', DownloadDocumentController::class)
    ->middleware(['auth']) // Optional: Ensure only logged-in users can download
    ->name('documents.download');
