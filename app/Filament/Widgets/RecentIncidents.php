<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Livewire\Attributes\On;

class RecentIncidents extends BaseWidget
{
    protected int | string | array $columnSpan = 6;

    protected static ?string $heading = 'Recent Incidents';

    public ?string $start_date = null;
    public ?string $end_date = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                IncidentResource::getEloquentQuery()
                    ->when($this->start_date, fn ($query) => $query->where('incident_date', '>=', $this->start_date))
                    ->when($this->end_date, fn ($query) => $query->where('incident_date', '<=', $this->end_date))
            )
            ->defaultPaginationPageOption(5)
            ->defaultSort('incident_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('incident_date')->dateTime(),
                Tables\Columns\TextColumn::make('title'),
                Tables\Columns\TextColumn::make('severity')->badge()->formatStateUsing(fn (string $state): string => strtoupper($state))->color(fn (string $state): string => match ($state) {
                    'p1', 'X1' => 'danger',
                    'p2', 'X2' => 'warning',
                    'p3', 'X3' => 'info',
                    'p4', 'X4' => 'success',
                    'Non Incident' => 'secondary',
                    default => 'secondary',
                }),
                Tables\Columns\TextColumn::make('latestStatusUpdate.status')->label('Latest Status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Open' => 'warning',
                        'Investigation' => 'info',
                        'Monitoring' => 'primary',
                        'Resolved' => 'success',
                        'Recovered' => 'success',
                        'Closed' => 'danger',
                        default => 'secondary',
                    }),
            ]);
    }

    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
    }
}