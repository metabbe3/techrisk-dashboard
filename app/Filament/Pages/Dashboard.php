<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\DoneActionImprovement;
use App\Filament\Widgets\FundLoss;
use App\Filament\Widgets\FundLossTrendChart;
use App\Filament\Widgets\IncidentsByLabelChart;
use App\Filament\Widgets\IncidentsByPicChart;
use App\Filament\Widgets\IncidentsBySeverityChart;
use App\Filament\Widgets\IncidentsByTypeChart;
use App\Filament\Widgets\LastIncident;
use App\Filament\Widgets\MonthlyIncidentsChart;
use App\Filament\Widgets\MtbfStat;
use App\Filament\Widgets\MttrMtbfTrendChart;
use App\Filament\Widgets\MttrStat;
use App\Filament\Widgets\OpenIncidents;
use App\Filament\Widgets\PendingActionImprovement;
use App\Filament\Widgets\RecentIncidents;
use App\Filament\Widgets\RecoveredFund;
use App\Filament\Widgets\TotalIncidents;
use App\Filament\Widgets\TotalIncidentsOnly;
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
            // Row 1: Total Incidents, Total Issues, Fund Loss (4 cols each = 12 cols)
            TotalIncidentsOnly::class,
            TotalIncidents::class,
            FundLoss::class,

            // Row 2: Recovered, Last Incident, MTTR (4 cols each = 12 cols)
            RecoveredFund::class,
            LastIncident::class,
            MttrStat::class,

            // Row 3: MTBF, Pending Action, Done Action (4 cols each = 12 cols)
            MtbfStat::class,
            PendingActionImprovement::class,
            DoneActionImprovement::class,

            // Row 4: Charts (3 charts, 4 columns each = 12 columns total)
            MonthlyIncidentsChart::class,
            IncidentsBySeverityChart::class,
            IncidentsByTypeChart::class,

            // Row 5: Incident Tables (2 tables, 6 columns each = 12 columns total)
            OpenIncidents::class,
            RecentIncidents::class,

            // Row 6: Additional Charts
            FundLossTrendChart::class,
            MttrMtbfTrendChart::class,

            // Row 7: More Analysis
            IncidentsByPicChart::class,
            IncidentsByLabelChart::class,
        ];
    }
}
