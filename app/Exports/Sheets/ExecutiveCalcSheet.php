<?php

declare(strict_types=1);
namespace App\Exports\Sheets;

use App\Enums\IncidentStatus;

use App\Enums\Severity;
use App\Filament\Statistics\IncidentStatsFooterData;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCharts;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;

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
            'open' => $rows->where('incident_status', '!=', IncidentStatus::Completed->value)->count(),
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
