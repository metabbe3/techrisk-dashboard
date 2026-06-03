<?php

namespace App\Filament\Widgets;

use App\Models\AiUsageLog;
use Filament\Widgets\Widget;

class AiTopUsersWidget extends Widget
{
    protected static string $view = 'filament.widgets.ai-top-users-widget';

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function getTopUsers(): \Illuminate\Support\Collection
    {
        return AiUsageLog::where('requested_at', '>=', now()->subDays(30))
            ->selectRaw('user_email, SUM(total_tokens) as total_tokens, COUNT(*) as request_count, AVG(response_time_ms) as avg_response_time')
            ->groupBy('user_email')
            ->orderByDesc('total_tokens')
            ->limit(10)
            ->get();
    }
}
