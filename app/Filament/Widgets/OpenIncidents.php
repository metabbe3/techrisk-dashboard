<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\IncidentResource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Livewire\Attributes\On;

class OpenIncidents extends BaseWidget
{
    protected int|string|array $columnSpan = 6;

    protected static ?string $heading = 'Open Incidents';

    public ?string $start_date = null;

    public ?string $end_date = null;

    public function table(Table $table): Table
    {
        $query = IncidentResource::getEloquentQuery()
            ->where('classification', 'Incident')
            ->whereIn('incident_status', ['Open', 'In progress', 'Finalization']);

        if ($this->start_date && $this->end_date) {
            $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
        } else {
            $query->whereYear('incident_date', now()->year);
        }

        return $table
            ->query($query)
            ->defaultPaginationPageOption(5)
            ->defaultSort('incident_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('incident_date')->dateTime(),
                Tables\Columns\TextColumn::make('title'),
                Tables\Columns\TextColumn::make('severity')->badge()->color(fn (string $state): string => match ($state) {
                    'P1' => 'danger',
                    'P2' => 'warning',
                    'P3' => 'info',
                    'P4' => 'success',
                    'Non Incident' => 'secondary',
                    default => 'secondary',
                }),
                Tables\Columns\TextColumn::make('incident_status')->badge()->color(fn (string $state): string => match ($state) {
                    'Open' => 'warning',
                    'In progress' => 'info',
                    'Finalization' => 'primary',
                    'Completed' => 'success',
                    default => 'gray',
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
