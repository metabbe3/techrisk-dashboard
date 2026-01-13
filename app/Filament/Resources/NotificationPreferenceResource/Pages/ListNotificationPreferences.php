<?php

namespace App\Filament\Resources\NotificationPreferenceResource\Pages;

use App\Filament\Resources\NotificationPreferenceResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNotificationPreferences extends ListRecords
{
    protected static string $resource = NotificationPreferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make(),
        ];
    }
}
