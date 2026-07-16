<?php

use App\Models\ApiAuditLog;
use App\Models\ReportTemplate;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prune API audit logs older than the configured retention (default 30 days).
// See \App\Models\ApiAuditLog::prunable().
Schedule::command('model:prune', ['--model' => ApiAuditLog::class])->daily()->description('Prune API audit logs older than retention window');

// AI model health check — ping every configured model through the gateway and
// cache reachability + latency so the model pickers can badge models. Runs once
// nightly (each ping consumes tokens; every-15-min was wasteful). The result is
// cached ~24h so it lasts all day — real-time per-model failure detection is
// handled by the CircuitBreaker, which trips on actual call failures regardless.
// No-ops when AI_MODEL_HEALTH_CHECK=false.
Schedule::command('ai:check-model-health')->dailyAt('02:17')->withoutOverlapping()->description('Nightly model health ping; cached ~24h (CircuitBreaker covers real-time failures)');

// ---------------------------------------------------------------------------
// Reminders, maintenance & scheduled reports.
// Ported from the legacy App\Console\Kernel::schedule(), which this app's
// scheduler does not invoke — scheduling lives here (Schedule facade) instead.
// ---------------------------------------------------------------------------

// Action-improvement reminders — daily at 08:00
Schedule::command('reminders:send-action-improvements')->dailyAt('08:00');
// Incident & unsettled fund-loss reminders via Netcore — daily at 08:15
Schedule::command('reminders:send-incidents')->dailyAt('08:15');
// Weekly overdue digest for admins — Mondays at 09:00
Schedule::command('reminders:send-weekly-overdue-digest')->weeklyOn(1, '09:00');
// Clean up old notifications — daily at 02:00
Schedule::command('notifications:clean')->dailyAt('02:00');
// Re-index stale RAG documents — daily at 02:30
Schedule::command('rag:reindex-stale')->dailyAt('02:30');

// Scheduled report templates — one schedule per template, by cadence. Wrapped
// in a try/catch because this file loads at console-kernel boot (including the
// `artisan test` runner boot, which happens before the testing env applies, and
// on fresh installs before migrations). A query against a missing/unavailable
// table must never break kernel boot; schedules never fire in tests anyway.
try {
    foreach (ReportTemplate::where('schedule', 'daily')->get() as $template) {
        Schedule::command('app:send-report', [$template->id])->daily();
    }
    foreach (ReportTemplate::where('schedule', 'weekly')->get() as $template) {
        Schedule::command('app:send-report', [$template->id])->weekly();
    }
    foreach (ReportTemplate::where('schedule', 'monthly')->get() as $template) {
        Schedule::command('app:send-report', [$template->id])->monthly();
    }
} catch (\Throwable $e) {
    // Table/DB unavailable (fresh install or test boot) — skip schedule registration.
}
