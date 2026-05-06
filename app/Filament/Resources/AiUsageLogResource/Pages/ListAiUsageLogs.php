<?php

namespace App\Filament\Resources\AiUsageLogResource\Pages;

use App\Filament\Resources\AiUsageLogResource;
use App\Filament\Widgets\AiTokenUsageTrendChart;
use App\Filament\Widgets\AiUsageStatsOverview;
use Filament\Actions;
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
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            AiTokenUsageTrendChart::class,
        ];
    }
}
