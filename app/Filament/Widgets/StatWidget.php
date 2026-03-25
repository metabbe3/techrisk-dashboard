<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StatWidget extends BaseWidget
{
    public ?string $query = null;

    /** @var array<mixed> */
    public array $bindings = [];

    public ?string $label = null;

    public ?string $icon = null;

    protected function getStats(): array
    {
        if (! $this->query) {
            return [];
        }

        try {
            // Use parameterized queries with bindings to prevent SQL injection
            $result = DB::select($this->query, $this->bindings);
            $value = $result[0]->value ?? 0;
        } catch (\Exception $e) {
            // Log the error for debugging
            logger()->error('StatWidget query error', [
                'query' => $this->query,
                'error' => $e->getMessage(),
            ]);
            $value = 'Error';
        }

        return [
            Stat::make($this->label, $value)
                ->icon($this->icon),
        ];
    }
}
