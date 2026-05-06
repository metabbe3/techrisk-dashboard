<?php

namespace App\Filament\Widgets;

use App\Models\AiUsageLog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AiUsageStatsOverview extends BaseWidget
{
    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $query = AiUsageLog::query();

        if (! auth()->user()->hasRole('admin')) {
            $query->where('user_id', auth()->id());
        }

        $last30Days = $query->clone()->where('requested_at', '>=', now()->subDays(30));
        $today = $query->clone()->whereDate('requested_at', today());

        $totalRequests = $last30Days->count();
        $todayRequests = $today->count();

        $totalTokens = (clone $last30Days)->where('success', true)->sum('total_tokens');
        $promptTokens = (clone $last30Days)->where('success', true)->sum('prompt_tokens');
        $completionTokens = (clone $last30Days)->where('success', true)->sum('completion_tokens');

        $avgResponseTime = (clone $last30Days)->whereNotNull('response_time_ms')->avg('response_time_ms');

        $successCount = (clone $last30Days)->where('success', true)->count();
        $successRate = $totalRequests > 0 ? round(($successCount / $totalRequests) * 100, 1) : 0;

        return [
            Stat::make('Total Requests (30d)', number_format($totalRequests))
                ->description(number_format($todayRequests).' today')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color('info'),

            Stat::make('Total Tokens (30d)', number_format($totalTokens))
                ->description(number_format($promptTokens).' prompt / '.number_format($completionTokens).' completion')
                ->descriptionIcon('heroicon-o-bolt')
                ->color('warning'),

            Stat::make('Avg Response Time', round($avgResponseTime ?? 0).'ms')
                ->description($avgResponseTime < 3000 ? 'Fast' : ($avgResponseTime < 8000 ? 'Moderate' : 'Slow'))
                ->descriptionIcon('heroicon-o-clock')
                ->color(match (true) {
                    ($avgResponseTime ?? 0) < 3000 => 'success',
                    ($avgResponseTime ?? 0) < 8000 => 'warning',
                    default => 'danger',
                }),

            Stat::make('Success Rate', $successRate.'%')
                ->description($successCount.' of '.number_format($totalRequests).' requests')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color(match (true) {
                    $successRate >= 95 => 'success',
                    $successRate >= 80 => 'warning',
                    default => 'danger',
                }),
        ];
    }
}
