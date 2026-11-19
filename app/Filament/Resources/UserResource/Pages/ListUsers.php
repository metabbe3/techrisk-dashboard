<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Jobs\SyncMicrosoftDirectoryJob;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('syncDirectory')
                ->label('Sync from Entra')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('manage users'))
                ->action(function () {
                    SyncMicrosoftDirectoryJob::dispatch(auth()->id());

                    \Filament\Notifications\Notification::make()
                        ->title('Directory sync started')
                        ->body('Names, emails, job titles, teams and the org chart are on their way — you will get a notification here when it finishes.')
                        ->info()
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }

    // PROJ-025: Service Accounts fold in as a tab — same User model, so a
    // resource-level tab pair is impossible; this mirrors the hidden
    // ServiceAccountResource scope + permission. ⚠ resource tabs (Resources\
    // Components\Tab) have NO visible() — gate by building the array.
    public function getTabs(): array
    {
        // Default tab = the handful of portal accounts; the 2.5k directory
        // contacts (can_login=false) live behind their own tab.
        $tabs = [
            'portal' => Tab::make('Can Log In')
                ->modifyQueryUsing(fn (Builder $query) => $query->humanUsers()->where('can_login', true))
                ->badge(fn () => User::query()->humanUsers()->where('can_login', true)->count()),
            'directory' => Tab::make('Directory')
                ->modifyQueryUsing(fn (Builder $query) => $query->humanUsers()->where('can_login', false))
                ->badge(fn () => User::query()->humanUsers()->where('can_login', false)->count()),
        ];

        if (auth()->user()?->can('manage service accounts')) {
            $tabs['service'] = Tab::make('Service Accounts')
                ->modifyQueryUsing(fn (Builder $query) => $query->serviceAccounts())
                ->badge(fn () => User::query()->serviceAccounts()->count());
        }

        return $tabs;
    }
}
