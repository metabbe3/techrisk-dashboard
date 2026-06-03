<?php

namespace App\Filament\Widgets;

use App\Models\AiUsageLog;
use Filament\Widgets\ChartWidget;

class AiTokenUsageByModelChart extends ChartWidget
{
    protected static ?string $heading = 'Token Usage by Model (30 days)';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    protected function getData(): array
    {
        $query = AiUsageLog::where('success', true)
            ->where('requested_at', '>=', now()->subDays(30));

        if (! auth()->user()->hasRole('admin')) {
            $query->where('user_id', auth()->id());
        }

        if ($this->filter && $this->filter !== 'all') {
            $query->where('model', $this->filter);
        }

        $data = $query
            ->selectRaw('DATE(requested_at) as date, model, SUM(total_tokens) as tokens')
            ->groupByRaw('DATE(requested_at), model')
            ->orderBy('date')
            ->get();

        $dates = $data->pluck('date')->unique()->sort()->values();
        $models = $data->pluck('model')->unique()->values();
        $colors = ['#0d9488', '#f59e0b', '#6366f1', '#ef4444', '#8b5cf6', '#ec4899'];

        $datasets = [];
        foreach ($models as $i => $model) {
            $modelData = $data->where('model', $model);
            $datasets[] = [
                'label' => $model,
                'data' => $dates->map(fn ($d) => (int) ($modelData->firstWhere('date', $d)?->tokens ?? 0))->toArray(),
                'backgroundColor' => $colors[$i % count($colors)].'80',
                'borderColor' => $colors[$i % count($colors)],
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $dates->map(fn ($d) => \Carbon\Carbon::parse($d)->format('d M'))->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getFilters(): ?array
    {
        $models = AiUsageLog::query()
            ->distinct()
            ->pluck('model', 'model')
            ->filter()
            ->toArray();

        return array_merge(['all' => 'All Models'], $models);
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => [
                    'stacked' => true,
                    'beginAtZero' => true,
                    'ticks' => [
                        'callback' => 'function(value) { return value >= 1000 ? (value/1000) + "k" : value; }',
                    ],
                ],
            ],
            'plugins' => [
                'legend' => ['display' => true],
            ],
        ];
    }
}
