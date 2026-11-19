<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            // PROJ-025: Permissions lost its nav item to the "Access
            // Control" consolidation — this is the route in.
            Actions\Action::make('permissions')
                ->label('Permissions')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->url(PermissionResource::getUrl('index')),
        ];
    }
}
