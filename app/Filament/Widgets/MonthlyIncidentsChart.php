<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\Incident;
use Carbon\Carbon;
use Livewire\Attributes\On;

class MonthlyIncidentsChart extends ChartWidget
{

    protected static ?string $heading = 'Monthly Incidents';

    public ?string $start_date = null;
    public ?string $end_date = null;

    protected function getData(): array
    {
        $query = Incident::selectRaw('MONTH(incident_date) as month, COUNT(*) as count')
            ->groupBy('month')
            ->orderBy('month');

        if ($this->start_date) {
            $query->where('incident_date', '>=', $this->start_date);
        }

        if ($this->end_date) {
            $query->where('incident_date', '<=', $this->end_date);
        }

        $data = $query->pluck('count', 'month')->all();

        $labels = [];
        $values = [];
        for ($i = 1; $i <= 12; $i++) {
            $labels[] = Carbon::create()->month($i)->format('M');
            $values[] = $data[$i] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Incidents',
                    'data' => $values,
                    'backgroundColor' => '#36A2EB',
                    'borderColor' => '#9BD0F5',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    public function getColumnSpan(): int | string | array
    {
        return 4;
    }

    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
    }
}
