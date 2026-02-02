<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

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

        try {
            $data = DB::select($this->query);
        } catch (\Exception $e) {
            // You can log the error here
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
