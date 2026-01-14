<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use App\Models\Label;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class IncidentsByLabelChart extends ChartWidget
{
    protected static ?string $heading = 'Incidents by Label';

    public ?string $start_date = null;
    public ?string $end_date = null;

    protected function getData(): array
    {
        $query = Label::query()
            ->withCount(['incidents' => function ($query) {
                if ($this->start_date && $this->end_date) {
                    $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
                } else {
                    $query->whereYear('incident_date', now()->year);
                }
            }])
            ->having('incidents_count', '>', 0);
        
        $data = $query->pluck('incidents_count', 'name');

        return [
            'datasets' => [
                [
                    'label' => 'Incidents',
                    'data' => $data->values()->all(),
                ],
            ],
            'labels' => $data->keys()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'pie';
    }

    public function getColumnSpan(): int | string | array
    {
        return 6;
    }

    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
    }
}
