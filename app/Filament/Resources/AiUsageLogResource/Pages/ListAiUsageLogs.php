<?php

namespace App\Filament\Resources\AiUsageLogResource\Pages;

use App\Filament\Resources\AiUsageLogResource;
use App\Filament\Widgets\AiCostEstimateWidget;
use App\Filament\Widgets\AiFailureRateChart;
use App\Filament\Widgets\AiTokenUsageByModelChart;
use App\Filament\Widgets\AiTokenUsageTrendChart;
use App\Filament\Widgets\AiTopUsersWidget;
use App\Filament\Widgets\AiUsageStatsOverview;
use Filament\Resources\Pages\ListRecords;

class ListAiUsageLogs extends ListRecords
{
    protected static string $resource = AiUsageLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTitle(): string
    {
        return 'AI Usage Logs';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AiUsageStatsOverview::class,
            AiCostEstimateWidget::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            AiTokenUsageTrendChart::class,
            AiTokenUsageByModelChart::class,
            AiFailureRateChart::class,
            AiTopUsersWidget::class,
        ];
    }
}
