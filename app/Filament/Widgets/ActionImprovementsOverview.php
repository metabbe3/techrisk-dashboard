<?php

namespace App\Filament\Widgets;

use App\Models\ActionImprovement;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;

class ActionImprovementsOverview extends BaseWidget
{
    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 8,
    ];

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getColumns(): int
    {
        return 2;
    }

    protected function getStats(): array
    {
        // Generate dynamic cache key based on filters
        $cacheKey = 'action_improvements_'.md5(json_encode([
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'year' => now()->year,
        ]));

        return Cache::remember($cacheKey, now()->addMinutes(5), function () {
            $query = ActionImprovement::query();

            $descriptionPeriod = 'this year';
            if ($this->start_date && $this->end_date) {
                $query->whereBetween('created_at', [$this->start_date, $this->end_date]);
                $descriptionPeriod = 'in the selected period';
            } else {
                $query->whereYear('created_at', now()->year);
            }

            // Optimized: Use single GROUP BY query instead of two separate count queries
            $results = $query->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status');

            $pendingCount = $results['pending'] ?? 0;
            $doneCount = $results['done'] ?? 0;

            return [
                Stat::make('Pending Action Improvements', $pendingCount)
                    ->description('Pending actions '.$descriptionPeriod)
                    ->color('warning'),
                Stat::make('Done Action Improvements', $doneCount)
                    ->description('Done actions '.$descriptionPeriod)
                    ->color('success'),
            ];
        });
    }

    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
    }
}
