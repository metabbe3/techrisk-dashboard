<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAccountResource\Pages;

use App\Filament\Resources\ServiceAccountResource;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Permission;

class CreateServiceAccount extends CreateRecord
{
    protected static string $resource = ServiceAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['is_service_account'] = true;

        return $data;
    }

    protected function afterCreate(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'access api']);
        $this->record->givePermissionTo($permission);
    }
}
