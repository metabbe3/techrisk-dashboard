<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccessRequestResource\Pages;

use App\Filament\Resources\AccessRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListAccessRequests extends ListRecords
{
    protected static string $resource = AccessRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No actions - requests are created via public form
        ];
    }

    public function getTitle(): string
    {
        return 'Access Requests';
    }
}
