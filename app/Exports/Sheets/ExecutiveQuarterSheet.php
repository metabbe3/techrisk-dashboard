<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

use App\Enums\Severity;
use App\Exports\Concerns\QuarterRange;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Per-quarter executive tab (owner addendum 2026-09-30): the widget-aligned
 * KPI cards scoped to one quarter, plus a severity breakdown row (P1–P4 and
 * one combined X1–X4 card) rendered with the same card styling.
 */
class ExecutiveQuarterSheet implements ShouldAutoSize, WithEvents, WithTitle
{
    /** @var array<string, mixed> */
    protected array $kpi;

    /** @var array<string, int> */
    protected array $severityCounts;

    protected string $label;

    public function __construct(Builder $query, string $quarter)
    {
        $rows = $query->clone()
            ->whereBetween('incident_date', QuarterRange::dates($quarter))
            ->get();
        // Enum-cast collections compare through ->value (BUG-022).
        $sevOf = fn ($i) => $i->severity?->value ?? $i->severity;
        $eligible = $rows->filter(fn ($i) => in_array($sevOf($i), Severity::METRIC_ELIGIBLE));

        // avg MTBF: span/(count-1) over the quarter's eligible incident_dates —
        // same formula as GroupedIncidentsExport's per-group avgMtbf.
        $dates = $eligible->pluck('incident_date')->filter()->sort()->values();
        $avgMtbf = 0;
        if ($dates->count() > 1) {
            $spanDays = $dates->first()->startOfDay()->diffInDays($dates->last()->startOfDay());
            $avgMtbf = round($spanDays / ($dates->count() - 1), 1);
        }

        $this->kpi = ExecutiveCalcSheet::computeKpi($rows, $avgMtbf);

        $this->severityCounts = [];
        foreach (['P1', 'P2', 'P3', 'P4'] as $sev) {
            $this->severityCounts[$sev] = $eligible->filter(fn ($i) => $sevOf($i) === $sev)->count();
        }
        $this->severityCounts['X1–X4'] = $eligible->filter(fn ($i) => str_starts_with((string) $sevOf($i), 'X'))->count();

        $this->label = QuarterRange::label($quarter);
    }

    public function title(): string
    {
        return $this->label;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                ExecutiveSummarySheet::writeKpiCards($sheet, $this->kpi, $this->label.' — Executive Summary');

                $severityCards = [];
                foreach ($this->severityCounts as $sev => $count) {
                    $severityCards[] = [$sev, $count];
                }
                ExecutiveSummarySheet::writeCardRow($sheet, $severityCards, 7, 8);
            },
        ];
    }
}
