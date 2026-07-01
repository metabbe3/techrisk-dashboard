<?php

use App\Http\Controllers\Api\ActionImprovementController;
use App\Http\Controllers\Api\Ai\ExportController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\TokenController;
use Illuminate\Support\Facades\Route;

// Health check endpoint - no auth required
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// NOTE: credential-based /api/login was removed — the API is token-only.
// Issue tokens via the Filament admin (API Tokens resource), which sets a
// 30-day sliding expiry so actively-used tokens never expire.

Route::middleware(['auth:sanctum', 'check.api.access', 'check.api.token.access'])->group(function () {
    // Token management
    Route::post('/logout', [TokenController::class, 'logout']);
    Route::get('/v1/token/info', [TokenController::class, 'info']);

    // API v1 - Read operations (rate limit editable in Admin → API Settings)
    Route::prefix('v1')->middleware('throttle:incidents')->group(function () {
        Route::apiResource('incidents', IncidentController::class)->only(['index', 'show']);
        Route::get('incidents-by-no/{no}', [IncidentController::class, 'showByNo']);
        Route::get('incidents-by-no/{no}/markdown', [IncidentController::class, 'showMarkdown']);
    });

    // Write operations - DISABLED for security (API is read-only)
    // Uncomment these routes if you need to enable write operations
    /*
    Route::middleware('throttle:20,1')->group(function () {
        Route::prefix('v1')->group(function () {
            Route::post('incidents', [IncidentController::class, 'store']);
            Route::put('incidents/{incident}', [IncidentController::class, 'update']);
            Route::patch('incidents/{incident}', [IncidentController::class, 'update']);
            Route::delete('incidents/{incident}', [IncidentController::class, 'destroy']);
        });

        Route::post('/incidents/{incident}/action-improvements', [ActionImprovementController::class, 'store']);
        Route::put('/action-improvements/{action_improvement}', [ActionImprovementController::class, 'update']);
        Route::patch('/action-improvements/{action_improvement}', [ActionImprovementController::class, 'update']);
        Route::delete('/action-improvements/{action_improvement}', [ActionImprovementController::class, 'destroy']);
    });
    */

    // Reference data (rate limit editable in Admin → API Settings)
    Route::prefix('v1')->middleware('throttle:reference')->group(function () {
        Route::get('labels', [IncidentController::class, 'getLabels']);
        Route::get('incident-types', [IncidentController::class, 'getIncidentTypes']);
        Route::get('categories', [IncidentController::class, 'getCategories']);
        Route::get('users', [IncidentController::class, 'getUsers']);
    });

    // Action improvements read (rate limit editable in Admin → API Settings)
    Route::prefix('v1')->middleware('throttle:actions')->group(function () {
        Route::get('/incidents/{incident}/action-improvements', [ActionImprovementController::class, 'index']);
        Route::get('/action-improvements/{action_improvement}', [ActionImprovementController::class, 'show']);
    });

    // AI Export endpoints - for bulk data ingestion (rate limit editable in Admin → API Settings)
    Route::prefix('v1/ai')->middleware('throttle:ai_export')->group(function () {
        Route::get('/export', [ExportController::class, 'export']);
    });
});
