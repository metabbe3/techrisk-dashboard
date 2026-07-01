<?php

use App\Models\ApiAuditLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prune API audit logs older than the configured retention (default 30 days).
// See \App\Models\ApiAuditLog::prunable().
Schedule::command('model:prune', ['--model' => ApiAuditLog::class])->daily()->description('Prune API audit logs older than retention window');
