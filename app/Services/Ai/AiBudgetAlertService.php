<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

class AiBudgetAlertService
{
    public function checkDailyBudget(): void
    {
        if (! config('ai.usage_dashboard.enabled', true)) {
            return;
        }

        $checkKey = 'ai_budget_alert_last_check';
        if (Cache::has($checkKey)) {
            return;
        }

        Cache::put($checkKey, true, 3600);

        $limit = config('ai.usage_dashboard.daily_token_limit', 1_000_000);
        if ($limit <= 0) {
            return;
        }
        $threshold = config('ai.usage_dashboard.budget_alert_threshold', 0.8);

        $todayTokens = AiUsageLog::whereDate('requested_at', today())
            ->where('success', true)
            ->sum('total_tokens');

        $pct = $todayTokens / $limit;

        if ($pct < $threshold) {
            return;
        }

        $notifyKey = 'ai_budget_alert_last_notified';
        if (Cache::has($notifyKey)) {
            return;
        }

        Cache::put($notifyKey, true, 14400);

        $percentageRounded = round($pct * 100, 1);

        Notification::make()
            ->title('AI Token Budget Alert')
            ->warning()
            ->body("Daily AI token usage is at {$percentageRounded}% of the budget (".number_format($todayTokens).' / '.number_format($limit).' tokens).')
            ->sendToDatabase(User::role('admin')->get());
    }
}
