<?php

namespace App\Exports;

use App\Enums\IncidentClassification;
use App\Enums\Severity;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCharts;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Executive Report export: Data sheet + Executive Summary sheet with KPI
 * cards and 4 native Excel charts (monthly incidents, severity mix, MTTR
 * trend, potential-vs-recovered funds).
 *
 * Scope mirrors the table query it is exported from (active year for the
 * current user, filtered set) — same rows the operator sees.
 */
class ExecutiveIncidentsExport implements WithMultipleSheets
{
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
}

/**
 * Raw data sheet — slim, readable columns (executives do not need 30 cols).
 */
class ExecutiveDataSheet implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithTitle
{
    protected Builder $query;

    public function __construct(Builder $query)
    {
        $this->query = $query;
    }

    public function title(): string
    {
        return 'Data';
    }

    public function query()
    {
        return $this->query;
    }

    public function headings(): array
    {
        return ['ID', 'Title', 'Date', 'Severity', 'Status', 'MTTR', 'MTBF (days)', 'Potential Loss', 'Actual Loss', 'Recovered'];
    }

    public function map($incident): array
    {
        return [
            $incident->no,
            $incident->title,
            $incident->incident_date?->format('d M Y'),
            $incident->severity instanceof \BackedEnum ? $incident->severity->value : $incident->severity,
            $incident->incident_status instanceof \BackedEnum ? $incident->incident_status->value : $incident->incident_status,
            $incident->mttr_formatted ?? ($incident->mttr !== null ? $incident->mttr : '-'),
            $incident->mtbf,
            (float) $incident->potential_fund_loss,
            (float) $incident->fund_loss,
            (float) $incident->recovered_fund,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $last = $sheet->getHighestDataRow();
                $col = $sheet->getHighestDataColumn();

                $sheet->getStyle("A1:{$col}1")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                for ($row = 2; $row <= $last; $row++) {
                    if ($row % 2 === 0) {
                        $sheet->getStyle("A{$row}:{$col}{$row}")
                            ->getFill()->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()->setARGB('FFF3F6FA');
                    }
                }

                $sheet->getStyle("A1:{$col}{$last}")
                    ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                // currency columns H:J
                $sheet->getStyle("H2:J{$last}")->getNumberFormat()
                    ->setFormatCode('"Rp "#,##0');
            },
        ];
    }
}

/**
 * Executive Summary sheet: KPI cards + 4 native Excel charts fed by a hidden
 * calc block written at the bottom of this same sheet.
 */
class ExecutiveCalcSheet implements FromCollection, ShouldAutoSize, WithCharts, WithHeadings, WithTitle
{
    protected Builder $query;

    /** @var array{months: string[], counts: int[], mttr: float[], pot: float[], rec: float[], sev: array, kpi: array} */
    public array $agg;

    public function __construct(Builder $query)
    {
        $this->query = $query->clone();
        $this->agg = $this->compute();
    }

    public function title(): string
    {
        return 'Calc';
    }

    public function headings(): array
    {
        return ['Month', 'Incidents', 'Severity', 'SevCount', 'AvgMTTR', 'Potential', 'Recovered'];
    }

    public function charts(): array
    {
        // Calc sheet rows 2..13 (row 1 = headings)
        $months = new DataSeriesValues('String', "Calc!\$A\$2:\$A\$13", null, 12);

        $incData = new DataSeriesValues('Number', "Calc!\$B\$2:\$B\$13", null, 12);
        $incChart = new Chart(
            'chart_incidents_month',
            new Title('Incidents per Month'),
            null,
            new PlotArea(null, [
                new DataSeries(DataSeries::TYPE_BARCHART, null, range(0, 0), [], [$months], [$incData]),
            ])
        );
        $incChart->setTopLeftPosition('B12');
        $incChart->setBottomRightPosition('H27');

        $sevLabels = new DataSeriesValues('String', "Calc!\$C\$2:\$C\$10", null, 9);
        $sevData = new DataSeriesValues('Number', "Calc!\$D\$2:\$D\$10", null, 9);
        $sevChart = new Chart(
            'chart_severity_mix',
            new Title('Severity Mix'),
            new Legend(Legend::POSITION_RIGHT),
            new PlotArea(null, [
                new DataSeries(DataSeries::TYPE_PIECHART, null, range(0, 0), [], [$sevLabels], [$sevData]),
            ])
        );
        $sevChart->setTopLeftPosition('J12');
        $sevChart->setBottomRightPosition('P27');

        $mttrData = new DataSeriesValues('Number', "Calc!\$E\$2:\$E\$13", null, 12);
        $mttrChart = new Chart(
            'chart_mttr_trend',
            new Title('Avg MTTR (minutes) per Month'),
            null,
            new PlotArea(null, [
                new DataSeries(DataSeries::TYPE_LINECHART, null, range(0, 0), [], [$months], [$mttrData]),
            ])
        );
        $mttrChart->setTopLeftPosition('B30');
        $mttrChart->setBottomRightPosition('H45');

        $potData = new DataSeriesValues('Number', "Calc!\$F\$2:\$F\$13", null, 12);
        $recData = new DataSeriesValues('Number', "Calc!\$G\$2:\$G\$13", null, 12);
        $fundChart = new Chart(
            'chart_funds',
            new Title('Potential vs Recovered Funds'),
            new Legend(Legend::POSITION_BOTTOM),
            new PlotArea(null, [
                new DataSeries(DataSeries::TYPE_BARCHART, null, range(0, 1), [], [$months], [$potData, $recData]),
            ])
        );
        $fundChart->setTopLeftPosition('J30');
        $fundChart->setBottomRightPosition('P45');

        return [$incChart, $sevChart, $mttrChart, $fundChart];
    }

    public function collection()
    {
        // 12 monthly rows + severity rows below (cols D/E) — chart series read A/G/F/G/H ranges.
        $rows = [];
        $nSev = count($this->agg['sev']);
        for ($i = 0; $i < max(12, $nSev); $i++) {
            $rows[] = [
                $this->agg['months'][$i] ?? null,
                $this->agg['counts'][$i] ?? null,
                $this->agg['sev'][$i][0] ?? null,
                $this->agg['sev'][$i][1] ?? null,
                $this->agg['mttr'][$i] ?? null,
                $this->agg['pot'][$i] ?? null,
                $this->agg['rec'][$i] ?? null,
            ];
        }

        return collect($rows);
    }

    private function compute(): array
    {
        $rows = $this->query->get();
        $eligible = $rows->filter(fn ($i) => in_array($i->severity?->value ?? $i->severity, Severity::METRIC_ELIGIBLE));

        $base = now()->startOfMonth()->subMonths(11);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = $base->copy()->addMonths($i)->format('M y');
        }

        $counts = array_fill(0, 12, 0);
        $mttrSum = array_fill(0, 12, 0);
        $mttrN = array_fill(0, 12, 0);
        $pot = array_fill(0, 12, 0);
        $rec = array_fill(0, 12, 0);
        foreach ($rows as $i) {
            if (! $i->incident_date) {
                continue;
            }
            $idx = 11 - $i->incident_date->startOfMonth()->diffInMonths(now()->startOfMonth());
            if ($idx < 0 || $idx > 11) {
                continue;
            }
            $counts[$idx]++;
            $pot[$idx] += (float) $i->potential_fund_loss;
            $rec[$idx] += (float) $i->recovered_fund;
            if (in_array($i->severity?->value ?? $i->severity, Severity::METRIC_ELIGIBLE) && $i->mttr !== null && $i->mttr >= 0) {
                $mttrSum[$idx] += $i->mttr;
                $mttrN[$idx]++;
            }
        }
        $mttrAvg = array_map(fn ($sv, $n) => $n > 0 ? round($sv / $n, 1) : 0, $mttrSum, $mttrN);

        $sev = [];
        foreach (Severity::cases() as $case) {
            $sev[] = [$case->value, $rows->where('severity', $case->value)->count()];
        }

        $avgMttrMins = round($eligible->where('mttr', '>=', 0)->avg('mttr') ?? 0, 1);
        $avgMttrDays = round(abs($eligible->where('mttr', '<', 0)->avg('mttr') ?? 0), 1);
        $avgMtbf = app(\App\Filament\Statistics\IncidentStatsFooterData::class)->build($this->query)['avgMtbf'];
        $potential = (float) $rows->sum('potential_fund_loss');
        $recovered = (float) $rows->sum('recovered_fund');

        $kpi = [
            'totalCases' => $rows->count(),
            'open' => $rows->where('incident_status', '!=', 'Completed')->count(),
            'avgMttrMins' => $avgMttrMins,
            'avgMttrDays' => $avgMttrDays,
            'avgMtbf' => $avgMtbf,
            'potential' => $potential,
            'recovered' => $recovered,
            'actual' => (float) $rows->sum('fund_loss'),
            'recoveryRate' => $potential > 0 ? round(($recovered / $potential) * 100, 1) : 0,
        ];

        return compact('months', 'counts', 'mttrAvg', 'pot', 'rec', 'sev', 'kpi') + ['mttr' => $mttrAvg];
    }
}

class ExecutiveSummarySheet implements ShouldAutoSize, WithEvents, WithTitle
{
    protected Builder $query;

    protected ExecutiveCalcSheet $calc;

    public function __construct(Builder $query, ExecutiveCalcSheet $calc)
    {
        $this->query = $query->clone();
        $this->calc = $calc;
    }

    public function title(): string
    {
        return 'Executive Summary';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $kpi = $this->calc->agg['kpi'];

                $sheet->mergeCells('A1:J2');
                $sheet->setCellValue('A1', 'EXECUTIVE INCIDENT REPORT — '.now()->format('d M Y'));
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);

                $kpis = [
                    ['Total Cases', $kpi['totalCases']],
                    ['Open', $kpi['open']],
                    ['Avg MTTR', $kpi['avgMttrMins'] > 0 ? "{$kpi['avgMttrMins']} min" : "{$kpi['avgMttrDays']} days"],
                    ['Avg MTBF', "{$kpi['avgMtbf']} days"],
                    ['Potential Loss', 'Rp '.number_format($kpi['potential'], 0, ',', '.')],
                    ['Recovered', 'Rp '.number_format($kpi['recovered'], 0, ',', '.')],
                    ['Actual Loss', 'Rp '.number_format($kpi['actual'], 0, ',', '.')],
                    ['Recovery Rate', $kpi['recoveryRate'].'%'],
                ];
                $col = 'A';
                foreach ($kpis as [$label, $value]) {
                    $sheet->setCellValue("{$col}4", $label);
                    $sheet->setCellValue("{$col}5", $value);
                    $sheet->getStyle("{$col}4")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '475569']],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    ]);
                    $sheet->getStyle("{$col}5")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '1E3A5F']],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    ]);
                    $sheet->getStyle("{$col}4:{$col}6")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                    $col++;
                }
            },
        ];
    }
}
