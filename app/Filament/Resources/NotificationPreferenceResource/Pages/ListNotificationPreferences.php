<?php

namespace App\Filament\Resources\NotificationPreferenceResource\Pages;

use App\Filament\Resources\NotificationPreferenceResource;
use App\Models\NotificationPreference;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNotificationPreferences extends ListRecords
{
    protected static string $resource = NotificationPreferenceResource::class;

    public function mount(): void
    {
        parent::mount();

        if (! auth()->user()?->hasRole('admin')) {
            $preference = NotificationPreference::firstOrCreate(
                ['user_id' => auth()->id()],
                ['user_id' => auth()->id()]
            );

            $this->redirect($this->getResource()::getUrl('edit', ['record' => $preference]));
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
