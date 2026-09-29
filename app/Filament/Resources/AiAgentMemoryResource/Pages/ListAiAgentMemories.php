<?php

declare(strict_types=1);
namespace App\Filament\Resources\AiAgentMemoryResource\Pages;

use App\Filament\Resources\AiAgentMemoryResource;
use Filament\Resources\Pages\ListRecords;

class ListAiAgentMemories extends ListRecords
{
    protected static string $resource = AiAgentMemoryResource::class;
}
