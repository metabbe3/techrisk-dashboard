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
use App\Models\UserDashboardPreference;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    public function getColumns(): int|string|array
    {
        return 12;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('customize_widgets')
                ->label('Customize Widgets')
                ->icon('heroicon-o-view-columns')
                ->url(ManageDashboardWidgets::getUrl()),
        ];
    }

    public function getWidgets(): array
    {
        $user = Auth::user();

        // Try to get user's widget preferences
        $userWidgets = UserDashboardPreference::where('user_id', $user->id)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->pluck('widget_class')
            ->toArray();

        // If user has preferences set, return those
        if (! empty($userWidgets)) {
            return $userWidgets;
        }

        // Initialize default preferences for first-time users
        UserDashboardPreference::initializeDefaultsForUser($user);

        // Return default widgets with organized layout
        return [
            // Row 1: Total Incidents, Total Issues, Last Incident (4 cols each = 12 cols)
            TotalIncidentsOnly::class,              // 4 cols - Total Incidents
            TotalIncidents::class,                  // 4 cols - Total Issues
            LastIncident::class,                    // 4 cols - Last Incident

            // Row 2: Fund Loss, Recovered, MTTR, MTBF (3 cols each = 12 cols)
            FundLoss::class,                        // 3 cols - Fund Loss
            RecoveredFund::class,                   // 3 cols - Recovered
            MttrStat::class,                        // 3 cols - MTTR
            MtbfStat::class,                        // 3 cols - MTBF

            // Row 3: Pending and Done Actions (6 cols each = 12 cols)
            PendingActionImprovement::class,        // 6 cols - Pending Actions
            DoneActionImprovement::class,           // 6 cols - Done Actions

            // Row 2: Charts (3 charts, 4 columns each = 12 columns total)
            MonthlyIncidentsChart::class,          // 4 cols
            IncidentsBySeverityChart::class,        // 4 cols
            IncidentsByTypeChart::class,            // 4 cols

            // Row 3: Incident Tables (2 tables, 6 columns each = 12 columns total)
            OpenIncidents::class,                   // 6 cols
            RecentIncidents::class,                 // 6 cols

            // Row 4: Additional Charts
            FundLossTrendChart::class,              // 6 cols
            MttrMtbfTrendChart::class,              // 6 cols

            // Row 5: More Analysis
            IncidentsByPicChart::class,             // 6 cols
            IncidentsByLabelChart::class,           // 6 cols
        ];
    }
}
