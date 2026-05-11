<?php

use App\Http\Controllers\Ai\AiSearchController;
use App\Http\Controllers\Ai\AnalyzeRootCauseController;
use App\Http\Controllers\Ai\AnalyzeTrendsController;
use App\Http\Controllers\Ai\ApplyLabelsController;
use App\Http\Controllers\Ai\ChatAgentsController;
use App\Http\Controllers\Ai\ChatCreateController;
use App\Http\Controllers\Ai\ChatDeleteController;
use App\Http\Controllers\Ai\ChatFinalizeController;
use App\Http\Controllers\Ai\ChatListController;
use App\Http\Controllers\Ai\ChatMessageFeedbackController;
use App\Http\Controllers\Ai\ChatMessagesController;
use App\Http\Controllers\Ai\ChatSendController;
use App\Http\Controllers\Ai\ChatStreamController;
use App\Http\Controllers\Ai\DetectSimilarController;
use App\Http\Controllers\Ai\EnhanceAgentPromptController;
use App\Http\Controllers\Ai\GenerateWeeklySummaryController;
use App\Http\Controllers\Ai\SuggestLabelsController;
use App\Http\Controllers\Ai\TextEnhanceController;
use App\Http\Controllers\Ai\WarRoomAvailableAgentsController;
use App\Http\Controllers\Ai\WarRoomCreateController;
use App\Http\Controllers\Ai\WarRoomDeleteController;
use App\Http\Controllers\Ai\WarRoomListController;
use App\Http\Controllers\Ai\WarRoomReanalyzeController;
use App\Http\Controllers\Ai\WarRoomPollController;
use App\Http\Controllers\Ai\WarRoomRetryController;
use App\Http\Controllers\Ai\WarRoomShowController;
use App\Http\Controllers\DownloadDocumentController;
use App\Http\Controllers\WeeklyReportExportController;
use App\Livewire\RequestAccessForm;
use Illuminate\Support\Facades\Route;

Route::post('/admin/ai/enhance-text', TextEnhanceController::class)
    ->middleware(['auth', 'can:manage incidents', 'ai.available:text,model'])
    ->name('ai.enhance-text');

Route::post('/admin/ai/enhance-agent-prompt', EnhanceAgentPromptController::class)
    ->middleware(['auth', 'can:manage incidents', 'ai.available:text,model'])
    ->name('ai.enhance-agent-prompt');

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

Route::post('/admin/ai/weekly-summary', GenerateWeeklySummaryController::class)
    ->middleware(['auth', 'can:access dashboard', 'ai.available:summary,key_highlights,areas_of_concern,recommendation'])
    ->name('ai.weekly-summary');

Route::post('/admin/ai/analyze-trends', AnalyzeTrendsController::class)
    ->middleware(['auth', 'can:access dashboard', 'ai.available:trends,recurring_issues,anomalies,recommendations'])
    ->name('ai.analyze-trends');

Route::post('/admin/ai/search', AiSearchController::class)
    ->middleware(['auth', 'can:manage incidents', 'ai.available:filters,explanation'])
    ->name('ai.search');

// AI Chat Assistant
Route::get('/admin/ai/chat/agents', ChatAgentsController::class)
    ->middleware(['auth', 'can:access ai chat'])
    ->name('ai.chat.agents');
Route::post('/admin/ai/chat/stream', ChatStreamController::class)
    ->middleware(['auth', 'can:access ai chat'])
    ->name('ai.chat.stream');
Route::post('/admin/ai/chat/finalize', ChatFinalizeController::class)
    ->middleware(['auth', 'can:access ai chat'])
    ->name('ai.chat.finalize');
Route::post('/admin/ai/chat/send', ChatSendController::class)
    ->middleware(['auth', 'can:access ai chat', 'ai.available:message,conversation_id'])
    ->name('ai.chat.send');
Route::get('/admin/ai/chat/conversations', ChatListController::class)
    ->middleware(['auth', 'can:access ai chat'])
    ->name('ai.chat.conversations');
Route::post('/admin/ai/chat/conversations', ChatCreateController::class)
    ->middleware(['auth', 'can:access ai chat'])
    ->name('ai.chat.create');
Route::delete('/admin/ai/chat/conversations/{id}', ChatDeleteController::class)
    ->middleware(['auth', 'can:access ai chat'])
    ->name('ai.chat.delete');
Route::post('/admin/ai/chat/messages/{id}/feedback', ChatMessageFeedbackController::class)
    ->middleware(['auth', 'can:access ai chat'])
    ->name('ai.chat.feedback');
Route::get('/admin/ai/chat/conversations/{id}/messages', ChatMessagesController::class)
    ->middleware(['auth', 'can:access ai chat'])
    ->name('ai.chat.messages');
Route::post('/admin/ai/chat/refresh-context', function () {
    app(\App\Services\Ai\ChatContextService::class)->clearDataCache();

    return response()->json([
        'success' => true,
        'freshness' => app(\App\Services\Ai\ChatContextService::class)->getDataFreshness(),
    ]);
})
    ->middleware(['auth', 'can:access ai chat'])
    ->name('ai.chat.refresh-context');

Route::get('/admin/ai/chat/incident-search', function (\Illuminate\Http\Request $request) {
    $q = $request->input('q', '');
    if (strlen($q) < 2) {
        return response()->json(['incidents' => []]);
    }

    $incidents = \App\Models\Incident::where('no', 'LIKE', "%{$q}%")
        ->orWhere('title', 'LIKE', "%{$q}%")
        ->orWhere('summary', 'LIKE', "%{$q}%")
        ->with('pic')
        ->orderBy('incident_date', 'desc')
        ->limit(10)
        ->get()
        ->map(fn ($inc) => [
            'id' => $inc->id,
            'no' => $inc->no,
            'title' => $inc->title ?? 'Untitled',
            'severity' => $inc->severity,
            'status' => $inc->incident_status,
            'date' => $inc->incident_date?->format('Y-m-d'),
            'pic' => $inc->pic?->name,
            'classification' => $inc->classification,
        ]);

    return response()->json(['incidents' => $incidents]);
})
    ->middleware(['auth', 'can:access ai chat'])
    ->name('ai.chat.incident-search');

// War Room
Route::prefix('admin/war-room')->middleware(['auth', 'can:access war room'])->group(function () {
    Route::get('/incident-search', function (\Illuminate\Http\Request $request) {
        $q = $request->input('q', '');
        if (strlen($q) < 2) {
            return response()->json(['incidents' => []]);
        }

        $incidents = \App\Models\Incident::where('no', 'LIKE', "%{$q}%")
            ->orWhere('title', 'LIKE', "%{$q}%")
            ->orWhere('summary', 'LIKE', "%{$q}%")
            ->with('pic')
            ->orderBy('incident_date', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($inc) => [
                'id' => $inc->id,
                'no' => $inc->no,
                'title' => $inc->title ?? 'Untitled',
                'severity' => $inc->severity,
                'status' => $inc->incident_status,
                'date' => $inc->incident_date?->format('Y-m-d'),
                'pic' => $inc->pic?->name,
                'classification' => $inc->classification,
            ]);

        return response()->json(['incidents' => $incidents]);
    })->name('war-room.incident-search');

    Route::get('/agents', WarRoomAvailableAgentsController::class)->name('war-room.agents');
    Route::get('/sessions', WarRoomListController::class)->name('war-room.sessions');
    Route::post('/sessions', WarRoomCreateController::class)->name('war-room.create');
    Route::get('/sessions/{id}', WarRoomShowController::class)->name('war-room.show');
    Route::post('/sessions/{id}/retry', WarRoomRetryController::class)->name('war-room.retry');
    Route::post('/sessions/{id}/reanalyze', WarRoomReanalyzeController::class)->name('war-room.reanalyze');
    Route::delete('/sessions/{id}', WarRoomDeleteController::class)->name('war-room.delete');
    Route::get('/sessions/{id}/poll', WarRoomPollController::class)->name('war-room.poll');
    Route::get('/sessions/{id}/export-pdf', WarRoomExportPdfController::class)->name('war-room.export-pdf');
});

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
