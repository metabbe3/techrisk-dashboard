<?php

declare(strict_types=1);
namespace App\Exports;

use App\Exports\Sheets\ExecutiveCalcSheet;
use App\Exports\Sheets\ExecutiveDataSheet;
use App\Exports\Sheets\ExecutiveSummarySheet;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Executive Report export: Data sheet + Executive Summary sheet with KPI
 * cards and 4 native Excel charts (monthly incidents, severity mix, MTTR
 * trend, potential-vs-recovered funds).
 *
 * Scope mirrors the table query it is exported from (active year for the
 * current user, filtered set) — same rows the operator sees.
 */
class ExecutiveIncidentsExport implements WithMultipleSheets, WithEvents
{
    use \App\Exports\Concerns\FillsChartCaches;
    protected Builder $query;

    public function __construct(Builder $query)
    {
        $this->query = $query->clone()->orderBy('incident_date', 'asc');
    }

    public function sheets(): array
    {
        $dataSheet = new ExecutiveDataSheet($this->query);
        $calcSheet = new ExecutiveCalcSheet($this->query);
        $summarySheet = new ExecutiveSummarySheet($this->query, $calcSheet);

        return [
            'Executive Summary' => $summarySheet,
            'Data' => $dataSheet,
            'Calc' => $calcSheet,
        ];
    }

    public function registerEvents(): array
    {
        // BUG-013: PhpSpreadsheet writes chart refs without value caches;
        // Numbers/QuickLook/Sheets then render empty charts. The Calc sheet is
        // written last and owns every chart, so on its AfterSheet hook we can
        // still mutate the in-memory chart objects before XML serialization.
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->getConcernable() === $this ? $event->sheet->getDelegate() : null;
                if ($sheet === null) {
                    return;
                }
                $this->fillChartCaches([$sheet]);
            },
        ];
    }
}
