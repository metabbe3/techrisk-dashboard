<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApiTokenResource\Pages;

use App\Filament\Resources\ApiTokenResource;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewApiToken extends ViewRecord
{
    protected static string $resource = ApiTokenResource::class;

    public ?string $plainTextToken = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->plainTextToken = session('api_token_plain_'.$record);
        session()->forget('api_token_plain_'.$record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            Action::make('regenerateToken')
                ->label('Regenerate Token')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Regenerate API Token')
                ->modalDescription('This will generate a new token value. The current token will stop working immediately.')
                ->modalSubmitActionLabel('Regenerate')
                ->action(function () {
                    $plainText = bin2hex(random_bytes(32));
                    $this->record->forceFill([
                        'token' => hash('sha256', $plainText),
                    ])->save();

                    $this->plainTextToken = $this->record->id.'|'.$plainText;
                }),

            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Revoke API Token')
                ->modalDescription('Are you sure you want to revoke this token? This action cannot be undone.')
                ->modalSubmitActionLabel('Revoke Token'),
        ];
    }

    public function getViewData(): array
    {
        return [
            'plainTextToken' => $this->plainTextToken,
            'showToken' => $this->plainTextToken !== null,
        ];
    }
}
