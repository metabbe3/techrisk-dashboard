<?php

namespace App\Services;

use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Incident;
use Carbon\Carbon;

class WeeklyDataService
{
    public function getWeeklyData(int $year): array
    {
        $currentDate = now();
        $weekData = [];

        $weeks = $this->getIsoWeeksInYear($year);

        $allIncidents = Incident::where('classification', IncidentClassification::Incident->value)
            ->whereYear('incident_date', $year)
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->excludedFromCounts()
            ->with(['pic', 'labels'])
            ->orderBy('incident_date', 'desc')
            ->get();

        $openStatuses = [
            IncidentStatus::Open,
            IncidentStatus::InProgress,
            IncidentStatus::Finalization,
        ];

        foreach ($weeks as $weekNumber => $dateRange) {
            if ($dateRange['start']->gt($currentDate)) {
                continue;
            }

            $weekStart = $dateRange['start']->copy()->startOfDay();
            $weekEnd = $dateRange['end']->copy()->endOfDay();

            $incidents = $allIncidents->filter(function ($incident) use ($weekStart, $weekEnd) {
                return $incident->incident_date->between($weekStart, $weekEnd);
            });

            $weekData[] = (object) [
                'week' => "W{$weekNumber}",
                'date_range' => $dateRange['start']->format('M j').' - '.$dateRange['end']->format('M j'),
                'incident_open' => $incidents->whereIn('incident_status', $openStatuses)->count(),
                'incident_closed' => $incidents->where('incident_status', IncidentStatus::Completed)->count(),
                'total' => $incidents->count(),
                'incidents' => $incidents->values(),
            ];
        }

        return $weekData;
    }

    public function getIsoWeeksInYear(int $year): array
    {
        $weeks = [];
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();

        $week1End = $yearStart->copy();
        while ($week1End->dayOfWeek !== 4) {
            $week1End->addDay();
        }

        $weeks[1] = [
            'start' => $yearStart->copy(),
            'end' => $week1End->copy(),
        ];

        $currentFriday = $week1End->copy()->addDay();
        while ($currentFriday->dayOfWeek !== 5) {
            $currentFriday->addDay();
        }

        $weekNumber = 2;
        while ($currentFriday->year === $year) {
            $weeks[$weekNumber] = [
                'start' => $currentFriday->copy(),
                'end' => $currentFriday->copy()->addDays(6),
            ];

            $currentFriday->addWeek();
            $weekNumber++;

            if ($weekNumber > 53) {
                break;
            }
        }

        return $weeks;
    }
}
