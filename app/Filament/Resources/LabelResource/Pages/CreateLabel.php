<?php

declare(strict_types=1);
namespace App\Filament\Resources\LabelResource\Pages;

use App\Filament\Resources\LabelResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLabel extends CreateRecord
{
    protected static string $resource = LabelResource::class;

    protected function useDatabaseTransactions(): bool
    {
        return true;
    }
}
