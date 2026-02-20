<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDashboardPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'widget_class',
        'is_enabled',
        'sort_order',
        'column_span',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'column_span' => 'array',
    ];

    /**
     * Get the user that owns the dashboard preference.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the widget name attribute from available widgets definition.
     */
    public function getNameAttribute(): string
    {
        $widgets = self::getAvailableWidgets();
        foreach ($widgets as $key => $widget) {
            if ($widget['class'] === $this->widget_class) {
                return $widget['name'];
            }
        }

        return class_basename($this->widget_class);
    }

    /**
     * Get the widget description attribute from available widgets definition.
     */
    public function getDescriptionAttribute(): string
    {
        $widgets = self::getAvailableWidgets();
        foreach ($widgets as $key => $widget) {
            if ($widget['class'] === $this->widget_class) {
                return $widget['description'];
            }
        }

        return '';
    }

    /**
     * Get the widget icon attribute from available widgets definition.
     */
    public function getIconAttribute(): string
    {
        $widgets = self::getAvailableWidgets();
        foreach ($widgets as $key => $widget) {
            if ($widget['class'] === $this->widget_class) {
                return $widget['icon'];
            }
        }

        return 'heroicon-o-view-columns';
    }

    /**
     * Get enabled widgets for a user.
     * Returns ordered array of widget class names.
     */
    public static function getEnabledWidgetsForUser(User $user): array
    {
        return static::where('user_id', $user->id)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->pluck('widget_class')
            ->toArray();
    }

    /**
     * Get all available widgets with their metadata.
     */
    public static function getAvailableWidgets(): array
    {
        return [
            'total_incidents_only' => [
                'class' => \App\Filament\Widgets\TotalIncidentsOnly::class,
                'name' => 'Total Incidents',
                'description' => 'Total count of Incidents only (excludes Issues)',
                'icon' => 'heroicon-o-chart-bar',
                'default_span' => 3,
            ],
            'total_issues' => [
                'class' => \App\Filament\Widgets\TotalIncidents::class,
                'name' => 'Total Issues',
                'description' => 'Total count of all records (Incidents + Issues)',
                'icon' => 'heroicon-o-chart-bar',
                'default_span' => 3,
            ],
            'last_incident' => [
                'class' => \App\Filament\Widgets\LastIncident::class,
                'name' => 'Last Incident',
                'description' => 'Days since the last incident',
                'icon' => 'heroicon-o-clock',
                'default_span' => 3,
            ],
            'fund_loss' => [
                'class' => \App\Filament\Widgets\FundLoss::class,
                'name' => 'Fund Loss',
                'description' => 'Total fund loss amount',
                'icon' => 'heroicon-o-currency-dollar',
                'default_span' => 3,
            ],
            'recovered_fund' => [
                'class' => \App\Filament\Widgets\RecoveredFund::class,
                'name' => 'Recovered Fund',
                'description' => 'Total recovered fund amount',
                'icon' => 'heroicon-o-arrow-trending-up',
                'default_span' => 3,
            ],
            'mttr_stat' => [
                'class' => \App\Filament\Widgets\MttrStat::class,
                'name' => 'MTTR',
                'description' => 'Average recovery time',
                'icon' => 'heroicon-o-wrench-screwdriver',
                'default_span' => 3,
            ],
            'mtbf_stat' => [
                'class' => \App\Filament\Widgets\MtbfStat::class,
                'name' => 'MTBF',
                'description' => 'Average time between failures',
                'icon' => 'heroicon-o-shield-check',
                'default_span' => 3,
            ],
            'pending_action_improvement' => [
                'class' => \App\Filament\Widgets\PendingActionImprovement::class,
                'name' => 'Pending Actions',
                'description' => 'Count of pending action improvements',
                'icon' => 'heroicon-o-clock',
                'default_span' => 3,
            ],
            'done_action_improvement' => [
                'class' => \App\Filament\Widgets\DoneActionImprovement::class,
                'name' => 'Done Actions',
                'description' => 'Count of completed action improvements',
                'icon' => 'heroicon-o-check-circle',
                'default_span' => 3,
            ],
            'action_improvements_overview' => [
                'class' => \App\Filament\Widgets\ActionImprovementsOverview::class,
                'name' => 'Action Improvements Overview',
                'description' => 'Overview of action improvement status (pending, done)',
                'icon' => 'heroicon-o-check-circle',
                'default_span' => 6,
            ],
            'monthly_incidents' => [
                'class' => \App\Filament\Widgets\MonthlyIncidentsChart::class,
                'name' => 'Monthly Incidents',
                'description' => 'Bar chart showing incidents per month',
                'icon' => 'heroicon-o-calendar',
                'default_span' => 'full', // 12
            ],
            'incidents_by_severity' => [
                'class' => \App\Filament\Widgets\IncidentsBySeverityChart::class,
                'name' => 'Incidents by Severity',
                'description' => 'Doughnut chart of incidents by severity level',
                'icon' => 'heroicon-o-exclamation-triangle',
                'default_span' => 4,
            ],
            'incidents_by_type' => [
                'class' => \App\Filament\Widgets\IncidentsByTypeChart::class,
                'name' => 'Incidents by Type',
                'description' => 'Pie chart showing tech vs non-tech incidents',
                'icon' => 'heroicon-o-tag',
                'default_span' => 4,
            ],
            'incidents_by_pic' => [
                'class' => \App\Filament\Widgets\IncidentsByPicChart::class,
                'name' => 'Incidents by PIC',
                'description' => 'Chart showing incidents by person in charge',
                'icon' => 'heroicon-o-user',
                'default_span' => 6,
            ],
            'incidents_by_label' => [
                'class' => \App\Filament\Widgets\IncidentsByLabelChart::class,
                'name' => 'Incidents by Label',
                'description' => 'Chart showing label distribution',
                'icon' => 'heroicon-o-tag',
                'default_span' => 6,
            ],
            'fund_loss_trend' => [
                'class' => \App\Filament\Widgets\FundLossTrendChart::class,
                'name' => 'Fund Loss Trend',
                'description' => 'Line chart showing fund loss over time',
                'icon' => 'heroicon-o-currency-dollar',
                'default_span' => 6,
            ],
            'mttr_mtbf_trend' => [
                'class' => \App\Filament\Widgets\MttrMtbfTrendChart::class,
                'name' => 'MTTR/MTBF Trend',
                'description' => 'Line chart showing MTTR and MTBF trends',
                'icon' => 'heroicon-o-trending-up',
                'default_span' => 6,
            ],
            'recent_incidents' => [
                'class' => \App\Filament\Widgets\RecentIncidents::class,
                'name' => 'Recent Incidents',
                'description' => 'Table showing recent incidents',
                'icon' => 'heroicon-o-clock',
                'default_span' => 6,
            ],
            'open_incidents' => [
                'class' => \App\Filament\Widgets\OpenIncidents::class,
                'name' => 'Open Incidents',
                'description' => 'Table showing open incidents',
                'icon' => 'heroicon-o-inbox',
                'default_span' => 6,
            ],
        ];
    }

    /**
     * Initialize default preferences for a user.
     */
    public static function initializeDefaultsForUser(User $user): void
    {
        $widgets = self::getAvailableWidgets();
        $sortOrder = 0;

        foreach ($widgets as $key => $widget) {
            static::create([
                'user_id' => $user->id,
                'widget_class' => $widget['class'],
                'is_enabled' => true,
                'sort_order' => $sortOrder++,
            ]);
        }
    }

    /**
     * Reset user preferences to defaults.
     */
    public static function resetForUser(User $user): void
    {
        static::where('user_id', $user->id)->delete();
        self::initializeDefaultsForUser($user);
    }
}
