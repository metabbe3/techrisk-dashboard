<?php

use App\Http\Controllers\DownloadDocumentController;
use App\Http\Controllers\WeeklyReportExportController;
use App\Livewire\AccessRequestForm;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin/login');

// Weekly report export
Route::get('/admin/weekly-report/export/{year}', WeeklyReportExportController::class)
    ->middleware(['auth', 'can:access dashboard'])
    ->name('filament.admin.pages.weekly-report-export');

// Access request form (public - uses standalone Livewire component)
Route::get('/request-access', AccessRequestForm::class)->name('request-access');

// Add this line
Route::get('/documents/{record}/download', DownloadDocumentController::class)
    ->middleware(['auth']) // Optional: Ensure only logged-in users can download
    ->name('documents.download');

// API Documentation - Manual route for OpenAPI spec (Scribe auto-route may not work in production)
Route::get('/docs.openapi', function () {
    $path = storage_path('app/private/scribe/openapi.yaml');
    if (!file_exists($path)) {
        abort(404, 'OpenAPI specification not found. Run: php artisan scribe:generate');
    }
    return response()->file($path, ['Content-Type' => 'application/yaml']);
})->name('docs.openapi');

Route::get('/docs.postman', function () {
    $path = storage_path('app/private/scribe/collection.json');
    if (!file_exists($path)) {
        abort(404, 'Postman collection not found. Run: php artisan scribe:generate');
    }
    return response()->file($path, ['Content-Type' => 'application/json']);
})->name('docs.postman');
