<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAccountResource\Pages;

use App\Filament\Resources\ServiceAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditServiceAccount extends EditRecord
{
    protected static string $resource = ServiceAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
