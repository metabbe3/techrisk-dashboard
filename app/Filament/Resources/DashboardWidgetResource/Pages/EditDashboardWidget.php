<?php

namespace App\Filament\Resources\DashboardWidgetResource\Pages;

use App\Filament\Resources\DashboardWidgetResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDashboardWidget extends EditRecord
{
    protected static string $resource = DashboardWidgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
