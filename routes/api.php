<?php

use App\Http\Controllers\Api\ActionImprovementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IncidentController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'check.api.access'])->group(function () {
    Route::prefix('v1')->group(function () { // Add v1 prefix for versioning
        Route::apiResource('incidents', IncidentController::class);
        Route::get('labels', [IncidentController::class, 'getLabels']);
        Route::get('incident-types', [IncidentController::class, 'getIncidentTypes']);
    });

    // Existing routes for action improvements
    Route::get('/incidents/{incident}/action-improvements', [ActionImprovementController::class, 'index']);
    Route::post('/incidents/{incident}/action-improvements', [ActionImprovementController::class, 'store']);
    Route::get('/action-improvements/{action_improvement}', [ActionImprovementController::class, 'show']);
    Route::put('/action-improvements/{action_improvement}', [ActionImprovementController::class, 'update']);
    Route::patch('/action-improvements/{action_improvement}', [ActionImprovementController::class, 'update']);
    Route::delete('/action-improvements/{action_improvement}', [ActionImprovementController::class, 'destroy']);
});
