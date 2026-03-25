<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\DashboardStatsOverview;
use App\Filament\Widgets\FundLossTrendChart;
use App\Filament\Widgets\IncidentsByLabelChart;
use App\Filament\Widgets\IncidentsByPicChart;
use App\Filament\Widgets\IncidentsBySeverityChart;
use App\Filament\Widgets\IncidentsByTypeChart;
use App\Filament\Widgets\MonthlyIncidentsChart;
use App\Filament\Widgets\MttrMtbfTrendChart;
use App\Filament\Widgets\OpenIncidents;
use App\Filament\Widgets\RecentIncidents;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getColumns(): int|string|array
    {
        return 12;
    }

    public function getWidgets(): array
    {
        return [
            // Consolidated Stats Overview (all 9 stats in one widget)
            DashboardStatsOverview::class,

            // Row 2: Charts (3 charts, 4 columns each = 12 columns total)
            MonthlyIncidentsChart::class,
            IncidentsBySeverityChart::class,
            IncidentsByTypeChart::class,

            // Row 3: Incident Tables (2 tables, 6 columns each = 12 columns total)
            OpenIncidents::class,
            RecentIncidents::class,

            // Row 4: Additional Charts
            FundLossTrendChart::class,
            MttrMtbfTrendChart::class,

            // Row 5: More Analysis
            IncidentsByPicChart::class,
            IncidentsByLabelChart::class,
        ];
    }
}
