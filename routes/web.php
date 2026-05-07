<?php

use App\Http\Controllers\Ai\AnalyzeRootCauseController;
use App\Http\Controllers\Ai\ApplyLabelsController;
use App\Http\Controllers\Ai\DetectSimilarController;
use App\Http\Controllers\Ai\SuggestLabelsController;
use App\Http\Controllers\Ai\TextEnhanceController;
use App\Http\Controllers\DownloadDocumentController;
use App\Http\Controllers\WeeklyReportExportController;
use App\Livewire\RequestAccessForm;
use Illuminate\Support\Facades\Route;

Route::post('/admin/ai/enhance-text', TextEnhanceController::class)
    ->middleware(['auth', 'can:manage incidents', 'ai.available:text,model'])
    ->name('ai.enhance-text');

Route::post('/admin/ai/suggest-labels', SuggestLabelsController::class)
    ->middleware(['auth', 'can:manage incidents', 'ai.available:matched,suggested'])
    ->name('ai.suggest-labels');

Route::post('/admin/ai/apply-labels', ApplyLabelsController::class)
    ->middleware(['auth', 'can:manage incidents'])
    ->name('ai.apply-labels');

Route::post('/admin/ai/analyze-root-cause', AnalyzeRootCauseController::class)
    ->middleware(['auth', 'can:manage incidents', 'ai.available:root_cause,categories,contributing_factors,recommendation'])
    ->name('ai.analyze-root-cause');

Route::post('/admin/ai/detect-similar', DetectSimilarController::class)
    ->middleware(['auth', 'can:manage incidents', 'ai.available:similar'])
    ->name('ai.detect-similar');

Route::redirect('/', '/admin/login');

// Weekly report export
Route::get('/admin/weekly-report/export/{year}', WeeklyReportExportController::class)
    ->middleware(['auth', 'can:access dashboard'])
    ->name('filament.admin.pages.weekly-report-export');

// Access request form (public - Livewire component with Filament forms)
Route::get('/request-access', RequestAccessForm::class)->name('request-access');

// Add this line
Route::get('/documents/{record}/download', DownloadDocumentController::class)
    ->middleware(['auth']) // Optional: Ensure only logged-in users can download
    ->name('documents.download');

// API Documentation - Manual route for OpenAPI spec (Scribe auto-route may not work in production)
Route::get('/docs.openapi', function () {
    $path = storage_path('app/private/scribe/openapi.yaml');
    if (! file_exists($path)) {
        abort(404, 'OpenAPI specification not found. Run: php artisan scribe:generate');
    }

    return response()->file($path, ['Content-Type' => 'application/yaml']);
})->name('docs.openapi');

Route::get('/docs.postman', function () {
    $path = storage_path('app/private/scribe/collection.json');
    if (! file_exists($path)) {
        abort(404, 'Postman collection not found. Run: php artisan scribe:generate');
    }

    return response()->file($path, ['Content-Type' => 'application/json']);
})->name('docs.postman');
