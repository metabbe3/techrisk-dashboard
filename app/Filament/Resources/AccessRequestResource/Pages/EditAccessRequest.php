<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccessRequestResource\Pages;

use App\Filament\Resources\AccessRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAccessRequest extends EditRecord
{
    protected static string $resource = AccessRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
        ];
    }
}
