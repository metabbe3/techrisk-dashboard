<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApiTokenResource\Pages;

use App\Filament\Resources\ApiTokenResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditApiToken extends EditRecord
{
    protected static string $resource = ApiTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Revoke API Token')
                ->modalDescription('Are you sure you want to revoke this token? This action cannot be undone.')
                ->modalSubmitActionLabel('Revoke Token'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // These fields are managed separately on the token model
        $data['expires_at'] = $data['expires_at'] ?? now()->addMonths(6);
        $data['renewal_minutes'] = $data['renewal_minutes'] ?? null;

        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Token updated successfully';
    }
}
