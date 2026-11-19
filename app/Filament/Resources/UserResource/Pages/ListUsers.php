<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    // PROJ-025: Service Accounts fold in as a tab — same User model, so a
    // resource-level tab pair is impossible; this mirrors the hidden
    // ServiceAccountResource scope + permission.
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'service' => Tab::make('Service Accounts')
                ->modifyQueryUsing(fn (Builder $query) => $query->serviceAccounts())
                ->badge(fn () => User::query()->serviceAccounts()->count())
                ->visible(fn (): bool => auth()->user()?->can('manage service accounts') ?? false),
        ];
    }
}
