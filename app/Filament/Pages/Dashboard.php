<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\IncidentsBySeverityChart;
use App\Filament\Widgets\IncidentsByTypeChart;
use App\Filament\Widgets\IncidentStatsOverview;
use App\Filament\Widgets\MonthlyIncidentsChart;
use App\Filament\Widgets\OpenIncidents;
use App\Filament\Widgets\RecentIncidents;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getColumns(): int | string | array
    {
        return 12;
    }

    public function getWidgets(): array
    {
        return [
            IncidentStatsOverview::class,
            IncidentsBySeverityChart::class,
            IncidentsByTypeChart::class,
            MonthlyIncidentsChart::class,
            OpenIncidents::class,
            RecentIncidents::class,
        ];
    }
}
