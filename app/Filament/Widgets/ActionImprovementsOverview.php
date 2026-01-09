<?php

namespace App\Filament\Widgets;

use App\Models\ActionImprovement;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class ActionImprovementsOverview extends BaseWidget
{
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

        $openCount = (clone $query)->where('status', 'Open')->count();
        $inProgressCount = (clone $query)->where('status', 'In Progress')->count();
        $completedCount = (clone $query)->where('status', 'Completed')->count();

        return [
            Stat::make('Open Action Improvements', $openCount)
                ->description('Open actions ' . $descriptionPeriod)
                ->color('warning'),
            Stat::make('In Progress Action Improvements', $inProgressCount)
                ->description('In progress actions ' . $descriptionPeriod)
                ->color('info'),
            Stat::make('Completed Action Improvements', $completedCount)
                ->description('Completed actions ' . $descriptionPeriod)
                ->color('success'),
        ];
    }

    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
    }
}
