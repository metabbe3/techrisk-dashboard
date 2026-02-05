<?php

use App\Http\Controllers\Api\ActionImprovementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IncidentController;
use Illuminate\Support\Facades\Route;

// Public login endpoint - strict limit to prevent brute force
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1'); // 5 login attempts per minute

Route::middleware(['auth:sanctum', 'check.api.access'])->group(function () {
    // API v1 - Read operations (100 req/min)
    Route::prefix('v1')->middleware('throttle:100,1')->group(function () {
        Route::apiResource('incidents', IncidentController::class)->only(['index', 'show']);
        Route::get('incidents-by-no/{no}', [IncidentController::class, 'showByNo']);
        Route::get('incidents-by-no/{no}/markdown', [IncidentController::class, 'showMarkdown']);
    });

    // Write operations (20 req/min)
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

    // Reference data (30 req/min) - already cached
    Route::prefix('v1')->middleware('throttle:30,1')->group(function () {
        Route::get('labels', [IncidentController::class, 'getLabels']);
        Route::get('incident-types', [IncidentController::class, 'getIncidentTypes']);
    });

    // Action improvements read (60 req/min)
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('/incidents/{incident}/action-improvements', [ActionImprovementController::class, 'index']);
        Route::get('/action-improvements/{action_improvement}', [ActionImprovementController::class, 'show']);
    });
});
