<?php

namespace App\Filament\Widgets;

use App\Models\AiUsageLog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AiCostEstimateWidget extends BaseWidget
{
    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $query = AiUsageLog::where('success', true)
            ->where('requested_at', '>=', now()->subDays(30));

        if (! auth()->user()->hasRole('admin')) {
            $query->where('user_id', auth()->id());
        }

        $rates = config('ai.usage_dashboard.cost_per_token', []);
        $byModel = (clone $query)
            ->selectRaw('model, SUM(total_tokens) as tokens')
            ->groupBy('model')
            ->get();

        $totalCost = 0;
        $breakdown = [];
        foreach ($byModel as $row) {
            $rate = $rates[$row->model] ?? 0;
            $cost = $row->tokens * $rate;
            $totalCost += $cost;
            if ($cost > 0) {
                $breakdown[] = $row->model.': $'.number_format($cost, 4);
            }
        }

        $limit = config('ai.usage_dashboard.daily_token_limit', 1_000_000);
        $todayTokens = AiUsageLog::where('success', true)
            ->whereDate('requested_at', today())
            ->when(! auth()->user()->hasRole('admin'), fn ($q) => $q->where('user_id', auth()->id()))
            ->sum('total_tokens');
        $budgetPct = $limit > 0 ? $todayTokens / $limit : 0;

        return [
            Stat::make('Estimated Cost (30d)', '$'.number_format($totalCost, 2))
                ->description(count($breakdown) > 0 ? implode(' | ', $breakdown) : 'No cost data')
                ->descriptionIcon('heroicon-o-currency-dollar')
                ->color(match (true) {
                    $budgetPct < 0.5 => 'success',
                    $budgetPct < 0.8 => 'warning',
                    default => 'danger',
                }),
        ];
    }
}
