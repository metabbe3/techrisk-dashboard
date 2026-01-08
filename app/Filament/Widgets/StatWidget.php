<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StatWidget extends BaseWidget
{
    public ?string $query = null;
    public ?string $label = null;
    public ?string $icon = null;

    protected function getStats(): array
    {
        if (!$this->query) {
            return [];
        }

        try {
            $result = DB::select($this->query);
            $value = $result[0]->value ?? 0;
        } catch (\Exception $e) {
            // You can log the error here
            $value = 'Error';
        }

        return [
            Stat::make($this->label, $value)
                ->icon($this->icon),
        ];
    }
}
