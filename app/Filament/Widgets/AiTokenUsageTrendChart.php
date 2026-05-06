<?php

namespace App\Filament\Widgets;

use App\Models\AiUsageLog;
use Filament\Widgets\ChartWidget;

class AiTokenUsageTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Daily Token Usage (30 days)';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    protected function getData(): array
    {
        $query = AiUsageLog::where('success', true)
            ->where('requested_at', '>=', now()->subDays(30));

        if (! auth()->user()->hasRole('admin')) {
            $query->where('user_id', auth()->id());
        }

        $data = $query
            ->selectRaw('DATE(requested_at) as date, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion')
            ->groupByRaw('DATE(requested_at)')
            ->orderBy('date')
            ->get();

        $labels = [];
        $promptData = [];
        $completionData = [];

        foreach ($data as $row) {
            $labels[] = \Carbon\Carbon::parse($row->date)->format('d M');
            $promptData[] = (int) $row->prompt;
            $completionData[] = (int) $row->completion;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Prompt Tokens',
                    'data' => $promptData,
                    'borderColor' => '#0d9488',
                    'backgroundColor' => 'rgba(13, 148, 136, 0.1)',
                    'fill' => true,
                ],
                [
                    'label' => 'Completion Tokens',
                    'data' => $completionData,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => true,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'callback' => 'function(value) { return value >= 1000 ? (value/1000) + "k" : value; }',
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                ],
            ],
        ];
    }
}
