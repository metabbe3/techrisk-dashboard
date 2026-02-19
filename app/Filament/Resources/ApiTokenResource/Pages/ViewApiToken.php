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

    protected ?string $plainTextToken = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Try to get the plain token from session (only available once after creation)
        $this->plainTextToken = session('api_token_plain_'.$record);

        // Clear from session immediately
        session()->forget('api_token_plain_'.$record);
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Revoke API Token')
                ->modalDescription('Are you sure you want to revoke this token? This action cannot be undone.')
                ->modalSubmitActionLabel('Revoke Token'),
        ];

        // Only show copy button if we have the plain text token
        if ($this->plainTextToken) {
            $actions[] = Action::make('copyToken')
                ->label('Copy Token')
                ->icon('heroicon-o-clipboard-document')
                ->color('success')
                ->action(function () {
                    $this->dispatchBrowserEvent('copy-to-clipboard', [
                        'text' => $this->plainTextToken,
                    ]);

                    \Filament\Notifications\Notification::make()
                        ->title('Token copied to clipboard')
                        ->success()
                        ->send();
                });
        }

        return $actions;
    }

    public function getViewData(): array
    {
        return [
            'plainTextToken' => $this->plainTextToken,
            'showToken' => $this->plainTextToken !== null,
        ];
    }
}
