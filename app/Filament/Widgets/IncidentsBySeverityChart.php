<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class IncidentsBySeverityChart extends ChartWidget
{
    protected static ?string $heading = 'Incidents by Severity';

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getData(): array
    {
        $query = Incident::select('severity', DB::raw('count(*) as total'));

        if ($this->start_date && $this->end_date) {
            $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
        } else {
            $query->whereYear('incident_date', now()->year);
        }

        $query->groupBy('severity');

        $data = $query->pluck('total', 'severity')->all();

        return [
            'datasets' => [
                [
                    'label' => 'Incidents',
                    'data' => array_values($data),
                    'backgroundColor' => [
                        '#FF6384',
                        '#36A2EB',
                        '#FFCE56',
                        '#4BC0C0',
                        '#9966FF',
                        '#FF9F40',
                    ],
                ],
            ],
            'labels' => array_keys($data),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function getColumnSpan(): int|string|array
    {
        return 4;
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                    'labels' => [
                        'boxWidth' => 12,
                        'padding' => 8,
                        'font' => [
                            'size' => 11,
                        ],
                    ],
                ],
            ],
        ];
    }

    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
    }
}
