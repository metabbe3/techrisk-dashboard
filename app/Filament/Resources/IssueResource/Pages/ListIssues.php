<?php

namespace App\Filament\Resources\IssueResource\Pages;

use App\Filament\Importers\IssuesImporter;
use App\Filament\Resources\IssueResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIssues extends ListRecords
{
    protected static string $resource = IssueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ImportAction::make()
                ->importer(IssuesImporter::class)
                ->visible(fn (): bool => auth()->user()->can('manage issues')),
            Actions\CreateAction::make()
                ->visible(fn (): bool => auth()->user()->can('manage issues')),
        ];
    }
}
