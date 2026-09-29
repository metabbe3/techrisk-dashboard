<?php

declare(strict_types=1);

namespace App\Filament\Filters;

use Carbon\Carbon;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class QuickPeriodFilter
{
    public static function make(): SelectFilter
    {
        return SelectFilter::make('quick_period')
            ->label('Quick Period')
            ->options([
                'week' => 'This Week',
                'month' => 'This Month',
                'year' => 'This Year',
                'all' => 'All Time',
            ])
            ->default('year')
            ->query(fn (Builder $query, array $data) => self::applyPeriod($query, $data['value'] ?? null));
    }

    public static function dateRange(): Filter
    {
        return Filter::make('custom_date_range')
            ->form([
                \Filament\Forms\Components\DatePicker::make('from')->label('From Date'),
                \Filament\Forms\Components\DatePicker::make('until')->label('Until Date'),
            ])
            ->query(fn (Builder $query, array $data) => self::applyDateRange($query, $data));
    }

    /**
     * Shared appliers — the table filters and the stats footer MUST scope
     * identically (footer == table by construction, BUG-018). Change the
     * scoping here, never in one consumer.
     *
     * @param  Builder<\App\Models\Incident>  $query
     */
    public static function applyPeriod(Builder $query, ?string $value): Builder
    {
        return match ($value) {
            'week' => $query->whereBetween('incident_date', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]),
            'month' => $query->whereBetween('incident_date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()]),
            'year' => $query->whereBetween('incident_date', [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()]),
            'all', null => $query,
            default => $query,
        };
    }

    /**
     * @param  Builder<\App\Models\Incident>  $query
     * @param  array{from?: ?string, until?: ?string}  $data
     */
    public static function applyDateRange(Builder $query, ?array $data): Builder
    {
        return $query
            ->when(
                $data['from'] ?? null,
                fn (Builder $query, $date) => $query->whereDate('incident_date', '>=', $date)
            )
            ->when(
                $data['until'] ?? null,
                fn (Builder $query, $date) => $query->whereDate('incident_date', '<=', $date)
            );
    }
}
