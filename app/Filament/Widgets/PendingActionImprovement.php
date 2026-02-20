<?php

namespace App\Filament\Widgets;

use App\Models\ActionImprovement;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class PendingActionImprovement extends BaseWidget
{
    protected int|string|array $columnSpan = [
        'md' => 3,
        'xl' => 3,
    ];

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getStats(): array
    {
        $query = ActionImprovement::query();
        $descriptionPeriod = 'this year';

        if ($this->start_date && $this->end_date) {
            $query->whereBetween('created_at', [$this->start_date, $this->end_date]);
            $descriptionPeriod = 'in the selected period';
        } else {
            $query->whereYear('created_at', now()->year);
        }

        $pendingCount = $query->where('status', 'pending')->count();

        return [
            Stat::make('Pending Action', $pendingCount)
                ->description('Pending actions '.$descriptionPeriod)
                ->color('warning'),
        ];
    }

    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
    }
}
