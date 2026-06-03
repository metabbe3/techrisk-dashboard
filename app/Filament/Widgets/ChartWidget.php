<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget as BaseWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChartWidget extends BaseWidget
{
    protected int|string|array $columnSpan = [
        'md' => 3,
        'lg' => 6,
    ];

    public ?string $query = null;

    public ?string $chartType = 'bar';

    protected function getData(): array
    {
        if (! $this->query) {
            return [];
        }

        $cacheKey = 'chart_widget_'.md5($this->query);

        // Only allow SELECT queries to prevent SQL injection
        $normalizedQuery = trim(preg_replace('/\s+/', ' ', strtoupper($this->query)));
        if (! str_starts_with($normalizedQuery, 'SELECT')) {
            Log::warning('ChartWidget rejected non-SELECT query', ['query' => $this->query]);

            return [];
        }

        // Block destructive keywords anywhere in the query
        $blocked = ['DROP ', 'DELETE ', 'UPDATE ', 'INSERT ', 'ALTER ', 'CREATE ', 'TRUNCATE ', 'EXEC ', 'EXECUTE '];
        foreach ($blocked as $keyword) {
            if (str_contains($normalizedQuery, $keyword)) {
                Log::warning('ChartWidget rejected query with blocked keyword', ['keyword' => $keyword, 'query' => $this->query]);

                return [];
            }
        }

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(15), function () {
                return DB::select($this->query);
            });
        } catch (\Exception $e) {
            Log::error('ChartWidget query failed', [
                'query' => $this->query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }

        $labels = array_map(fn ($item) => $item->label, $data);
        $values = array_map(fn ($item) => $item->value, $data);

        return [
            'datasets' => [
                [
                    'label' => $this->getHeading(),
                    'data' => $values,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return $this->chartType;
    }
}
