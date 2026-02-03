<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccessRequestResource\Pages;

use App\Filament\Resources\AccessRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAccessRequest extends ViewRecord
{
    protected static string $resource = AccessRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()->hasRole('admin')),
        ];
    }
}
