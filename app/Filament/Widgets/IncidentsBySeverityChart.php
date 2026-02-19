<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class IncidentsBySeverityChart extends Widget
{
    protected static string $view = 'filament.widgets.incidents-by-severity-chart';

    protected static ?string $heading = 'Incidents by Severity';

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected int|string|array $columnSpan = 4;

    public $severityData = [];

    /**
     * Custom severity order for sorting.
     */
    protected array $severityOrder = [
        'P1' => 1,
        'P2' => 2,
        'P3' => 3,
        'P4' => 4,
        'Non Incident' => 5,
        'X1' => 6,
        'X2' => 7,
        'X3' => 8,
        'X4' => 9,
        'G' => 10,
        'N' => 11,
    ];

    public function mount(): void
    {
        $this->severityData = $this->getSeverityData();
    }

    /**
     * Get the severity data for the table.
     */
    protected function getSeverityData(): array
    {
        $cacheKey = 'incidents_by_severity_'.md5(json_encode([
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'year' => now()->year,
        ]));

        $results = Cache::remember($cacheKey, now()->addMinutes(15), function () {
            $query = Incident::select('severity', DB::raw('count(*) as total'))
                ->where('classification', 'Incident') // Only count Incidents, not Issues
                ->where(fn ($query) => $query->whereNull('fund_status')
                    ->orWhere('fund_status', '!=', 'Potential recovery')); // Exclude Potential recovery

            if ($this->start_date && $this->end_date) {
                $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
            } else {
                $query->whereYear('incident_date', now()->year);
            }

            $query->groupBy('severity');

            return $query->get();
        });

        // Ensure results is a collection
        if (is_array($results)) {
            $results = collect($results);
        }

        $total = $results->sum('total');

        return $results->map(function ($item) use ($total) {
            $percentage = $total > 0 ? round(($item->total / $total) * 100, 1) : 0;

            return [
                'severity' => $item->severity,
                'count' => $item->total,
                'percentage' => $percentage,
            ];
        })->sortBy(function ($item) {
            // Sort by custom severity order
            return $this->severityOrder[$item['severity']] ?? 999;
        })->values()->toArray();
    }

    /**
     * Get color for severity badge.
     */
    protected function getSeverityColor(string $severity): string
    {
        return match ($severity) {
            'P1' => 'danger',
            'P2' => 'warning',
            'P3' => 'info',
            'P4' => 'success',
            'G', 'N' => 'success',
            'Non Incident' => 'gray',
            'X1', 'X2', 'X3', 'X4' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Get color for percentage badge.
     */
    protected function getPercentageColor(float $percentage): string
    {
        return match (true) {
            $percentage >= 30 => 'danger',
            $percentage >= 20 => 'warning',
            $percentage >= 10 => 'info',
            default => 'success',
        };
    }

    /**
     * Listen for dashboard filter updates.
     */
    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
        $this->severityData = $this->getSeverityData();
    }
}
