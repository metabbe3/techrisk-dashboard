<?php

namespace App\Filament\Resources\IncidentResource\Pages;

use App\Filament\Resources\IncidentResource;
use Filament\Actions;
use Filament\Resources\Pages\Page;

class BoardIncidents extends Page
{
    protected static string $resource = IncidentResource::class;

    protected static string $view = 'filament.resources.incident-resource.pages.board-incidents';

    protected static ?string $title = 'Incident Board';

    public static function canAccess(array $parameters = []): bool
    {
        return IncidentResource::canViewAny();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('table_view')
                ->label('Table View')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->outlined()
                ->url(fn () => IncidentResource::getUrl('index'))
                ->extraAttributes(['class' => 'header-btn-table']),
            Actions\CreateAction::make()
                ->label('New Incident')
                ->icon('heroicon-o-plus')
                ->extraAttributes(['class' => 'header-btn-create'])
                ->visible(fn (): bool => auth()->user()->can('manage incidents')),
        ];
    }
}
