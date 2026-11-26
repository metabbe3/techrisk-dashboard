<?php

declare(strict_types=1);

namespace App\Filament\Resources\DohaTicketResource\Pages;

use App\Filament\Actions\DohaSyncAction;
use App\Filament\Resources\DohaTicketResource;
use Filament\Resources\Pages\ListRecords;

class ListDohaTickets extends ListRecords
{
    protected static string $resource = DohaTicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DohaSyncAction::make(),
        ];
    }
}
