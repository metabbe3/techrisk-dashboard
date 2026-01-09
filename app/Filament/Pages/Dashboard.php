<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\IncidentsBySeverityChart;
use App\Filament\Widgets\IncidentsByTypeChart;
use App\Filament\Widgets\IncidentStatsOverview;
use App\Filament\Widgets\MonthlyIncidentsChart;
use App\Filament\Widgets\OpenIncidents;
use App\Filament\Widgets\RecentIncidents;
use App\Filament\Widgets\IncidentsByPicChart;
use App\Filament\Widgets\FundLossTrendChart;
use App\Filament\Widgets\MttrMtbfTrendChart;
use App\Filament\Widgets\IncidentsByLabelChart;
use App\Filament\Widgets\ActionImprovementsOverview;
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
            IncidentsByPicChart::class,
            FundLossTrendChart::class,
            MttrMtbfTrendChart::class,
            IncidentsByLabelChart::class,
            ActionImprovementsOverview::class,
        ];
    }
}
