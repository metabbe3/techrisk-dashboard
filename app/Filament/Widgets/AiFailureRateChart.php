<?php

namespace App\Filament\Widgets;

use App\Models\AiUsageLog;
use Filament\Widgets\ChartWidget;

class AiFailureRateChart extends ChartWidget
{
    protected static ?string $heading = 'Success / Failure by Model (30d)';

    protected static ?string $pollingInterval = null;

    protected function getData(): array
    {
        $query = AiUsageLog::where('requested_at', '>=', now()->subDays(30));

        if (! auth()->user()->hasRole('admin')) {
            $query->where('user_id', auth()->id());
        }

        $data = $query
            ->selectRaw('model, SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as success_count, SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failure_count')
            ->groupBy('model')
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Success',
                    'data' => $data->pluck('success_count')->map(fn ($v) => (int) $v)->toArray(),
                    'backgroundColor' => '#10b981',
                ],
                [
                    'label' => 'Failure',
                    'data' => $data->pluck('failure_count')->map(fn ($v) => (int) $v)->toArray(),
                    'backgroundColor' => '#ef4444',
                ],
            ],
            'labels' => $data->pluck('model')->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
            ],
            'plugins' => [
                'legend' => ['display' => true],
            ],
        ];
    }
}
