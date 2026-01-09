<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use App\Models\IncidentType;
use Filament\Widgets\ChartWidget;
use Carbon\Carbon;
use Livewire\Attributes\On;

class IncidentsByTypeChart extends ChartWidget
{

    protected static ?string $heading = 'Incidents by Type';

    public ?string $start_date = null;
    public ?string $end_date = null;

    protected function getData(): array
    {
        $query = Incident::select('incident_type', \DB::raw('count(*) as total'));

        if ($this->start_date && $this->end_date) {
            $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
        } else {
            $query->whereYear('incident_date', now()->year);
        }

        $query->groupBy('incident_type');

        $data = $query->get();

        return [
            'datasets' => [
                [
                    'label' => 'Incidents',
                    'data' => $data->pluck('total')->all(),
                ],
            ],
            'labels' => $data->pluck('incident_type')->all(),
        ];
    }

            protected function getType(): string

            {

                return 'pie';

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

    