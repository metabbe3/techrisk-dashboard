<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update column_span from 3 to 4 for all stat widgets
        // This fixes the description overflow issue
        DB::statement("UPDATE user_dashboard_preferences
            SET column_span = '{\"md\": 4, \"xl\": 4}'
            WHERE widget_class IN (
                'App\\\\Filament\\\\Widgets\\\\TotalIncidentsOnly',
                'App\\\\Filament\\\\Widgets\\\\TotalIncidents',
                'App\\\\Filament\\\\Widgets\\\\LastIncident',
                'App\\\\Filament\\\\Widgets\\\\FundLoss',
                'App\\\\Filament\\\\Widgets\\\\RecoveredFund',
                'App\\\\Filament\\\\Widgets\\\\MttrStat',
                'App\\\\Filament\\\\Widgets\\\\MtbfStat',
                'App\\\\Filament\\\\Widgets\\\\PendingActionImprovement',
                'App\\\\Filament\\\\Widgets\\\\DoneActionImprovement'
            )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert column_span back to 3
        DB::statement("UPDATE user_dashboard_preferences
            SET column_span = '{\"md\": 3, \"xl\": 3}'
            WHERE widget_class IN (
                'App\\\\Filament\\\\Widgets\\\\TotalIncidentsOnly',
                'App\\\\Filament\\\\Widgets\\\\TotalIncidents',
                'App\\\\Filament\\\\Widgets\\\\LastIncident',
                'App\\\\Filament\\\\Widgets\\\\FundLoss',
                'App\\\\Filament\\\\Widgets\\\\RecoveredFund',
                'App\\\\Filament\\\\Widgets\\\\MttrStat',
                'App\\\\Filament\\\\Widgets\\\\MtbfStat',
                'App\\\\Filament\\\\Widgets\\\\PendingActionImprovement',
                'App\\\\Filament\\\\Widgets\\\\DoneActionImprovement'
            )");
    }
};
