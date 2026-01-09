<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use App\Models\User;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class IncidentsByPicChart extends ChartWidget
{
    protected static ?string $heading = 'Incidents by Person In Charge';

    public ?string $start_date = null;
    public ?string $end_date = null;

    protected function getData(): array
    {
        $query = Incident::query()
            ->select('users.name as pic_name', DB::raw('count(incidents.id) as total'))
            ->join('users', 'incidents.pic_id', '=', 'users.id')
            ->groupBy('users.name');

        if ($this->start_date && $this->end_date) {
            $query->whereBetween('incidents.incident_date', [$this->start_date, $this->end_date]);
        } else {
            $query->whereYear('incidents.incident_date', now()->year);
        }

        $data = $query->pluck('total', 'pic_name');

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
        return 'bar';
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
