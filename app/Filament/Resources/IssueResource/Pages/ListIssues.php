<?php

namespace App\Filament\Resources\IssueResource\Pages;

use App\Filament\Importers\IssuesImporter;
use App\Filament\Resources\IssueResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

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

    public function getTabs(): array
    {
        return [
            'All Issues' => Tab::make(),
            'MTTR' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereNotNull('mttr')
                    ->orderBy('mttr', 'desc')),
            'MTBF' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereNotNull('mtbf')
                    ->orderBy('mtbf', 'desc')),
        ];
    }
}
