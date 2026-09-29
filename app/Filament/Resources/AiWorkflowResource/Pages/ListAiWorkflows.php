<?php

declare(strict_types=1);
namespace App\Filament\Resources\AiWorkflowResource\Pages;

use App\Filament\Resources\AiWorkflowResource;
use Filament\Resources\Pages\ListRecords;

class ListAiWorkflows extends ListRecords
{
    protected static string $resource = AiWorkflowResource::class;
}
