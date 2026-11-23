<?php

use App\Http\Controllers\Api\ActionImprovementController;
use App\Http\Controllers\Api\AgentRunController;
use App\Http\Controllers\Api\AgentScheduleController;
use App\Http\Controllers\Api\Ai\ExportController;
use App\Http\Controllers\Api\CorpusController;
use App\Http\Controllers\Api\GovernanceController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\InvestigationFormController;
use App\Http\Controllers\Api\NotionController;
use App\Http\Controllers\Api\SkillController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\WeeklyReportController;
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

    // Notion read-only proxy for the DingTalk agent (search / fetch / query —
    // reuses the workspace OAuth server-side; rate limit editable in Admin → API Settings)
    Route::prefix('v1/notion')->middleware('throttle:reference')->group(function () {
        Route::post('search', [NotionController::class, 'search']);
        Route::post('fetch', [NotionController::class, 'fetch']);
        Route::post('query', [NotionController::class, 'query']);
    });

    // Action improvements read (rate limit editable in Admin → API Settings).
    // The flat list is the agent's cross-incident view ("overdue this week");
    // overdue=1 reuses the governance definition, never a hand-typed status pair.
    Route::prefix('v1')->middleware('throttle:actions')->group(function () {
        Route::get('/action-improvements', [ActionImprovementController::class, 'search']);
        Route::get('/incidents/{incident}/action-improvements', [ActionImprovementController::class, 'index']);
        Route::get('/action-improvements/{action_improvement}', [ActionImprovementController::class, 'show']);
    });

    // AI Export endpoints - for bulk data ingestion (rate limit editable in Admin → API Settings)
    Route::prefix('v1/ai')->middleware('throttle:ai_export')->group(function () {
        Route::get('/export', [ExportController::class, 'export']);
    });

    // DingTalk agent integration — chat reminders live as AiAgent rows and
    // every chat turn is reported as a run (first live API writes; the Go
    // agent is the only intended client). Rate limit editable in Admin → API Settings.
    Route::prefix('v1')->middleware('throttle:agent')->group(function () {
        Route::get('agent-schedules', [AgentScheduleController::class, 'index']);
        Route::post('agent-schedules', [AgentScheduleController::class, 'store']);
        Route::delete('agent-schedules/{no}', [AgentScheduleController::class, 'destroy']);
        Route::post('agent-runs', [AgentRunController::class, 'store']);
    });

    // Dashboard aggregate stats for the DingTalk agent — both modes delegate
    // verbatim to the shared metric builders (one-logic rule, zero new math).
    // Rate limit editable in Admin → API Settings.
    Route::prefix('v1')->middleware('throttle:agent')->group(function () {
        Route::get('stats', [StatsController::class, 'index']);
    });

    // Incident memory corpus pages (index.md → one page per incident/topic).
    // Relative paths INSIDE markdown/corpus only — traversal rejected below.
    Route::prefix('v1')->middleware('throttle:agent')->group(function () {
        Route::get('corpus', [CorpusController::class, 'index']);
    });

    // Governance KPIs for the DingTalk agent — verbatim GovernanceKpiService
    // output (one-logic rule: zero new metric math on the Go side).
    Route::prefix('v1')->middleware('throttle:agent')->group(function () {
        Route::get('governance', [GovernanceController::class, 'index']);
    });

    // Weekly report for the DingTalk agent — week counts verbatim from
    // WeeklyDataService (the Filament Weekly Report page's builder),
    // incident rows as the one compact list projection. Rate limit editable
    // in Admin → API Settings.
    Route::prefix('v1')->middleware('throttle:agent')->group(function () {
        Route::get('weekly-report', [WeeklyReportController::class, 'index']);
        Route::get('investigation-forms', [InvestigationFormController::class, 'index']);
    });

    // Agent skill library (Filament → Skills) + War Room personas — read-only,
    // on-demand: index is a metadata catalog, show loads ONE body (48k cap).
    // Persona bundles (role-<key>) carry the system prompt + skill NAMES only.
    Route::prefix('v1')->middleware('throttle:agent')->group(function () {
        Route::get('skills', [SkillController::class, 'index']);
        Route::get('skills/{name}', [SkillController::class, 'show'])
            ->where('name', '[A-Za-z0-9_-]{1,64}');
    });
});
