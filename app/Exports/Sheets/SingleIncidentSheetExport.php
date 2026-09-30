<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

use App\Enums\FundStatus;
use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\Severity;
use App\Exports\Concerns\IdrFormat;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class SingleIncidentSheetExport implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithTitle
{
    private $query;

    private $title;

    private $stats;

    private $headings;

    private $columnNames;

    /** Per-instance (NOT static): a static cache survived the request in long-lived FPM workers and froze the year's sequence — incidents created later dashed out mid-year (prod bug 2026-09-30). */
    private array $mtbfCache = [];

    public function __construct($query, string $title, array $headings, array $columnNames)
    {
        $this->query = $query;
        $this->title = $title;
        $this->headings = $headings;
        $this->columnNames = $columnNames;
    }

    public function query()
    {
        return $this->query;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function map($incident): array
    {
        $row = [];
        foreach ($this->columnNames as $columnName) {
            $isBoolean = in_array($columnName, ['glitch_flag', 'risk_incident_form_cfm', 'goc_upload', 'teams_upload', 'doc_signed']);
            $isArray = in_array($columnName, ['business_category', 'root_cause_category', 'responsible_team']);

            if ($columnName === 'mtbf') {
                $row[] = $this->computeMtbfForIncident($incident) ?? '-';
            } elseif ($columnName === 'mttr') {
                $row[] = $incident->mttr_formatted;
            } elseif ($columnName === 'recovery_rate') {
                if ((float) $incident->potential_fund_loss > 0) {
                    $rate = ((float) $incident->recovered_fund / (float) $incident->potential_fund_loss) * 100;
                    $row[] = number_format($rate, 1).'%';
                } else {
                    $row[] = '-';
                }
            } elseif ($isBoolean) {
                $row[] = $incident->{$columnName} ? 'Yes' : 'No';
            } elseif ($isArray) {
                $value = $incident->{$columnName};
                $row[] = is_array($value) ? implode(', ', $value) : ($value ?? '');
            } elseif (IdrFormat::isFundColumn($columnName)) {
                // Raw float + native Rp format (applied in AfterSheet), so fund cells stay summable
                $row[] = (float) $incident->{$columnName};
            } else {
                $value = $incident->{$columnName};
                // ponytail: enum-cast attrs (severity/status/classification) return BackedEnum instances
                // PhpSpreadsheet can't stringify them → coerce to the stored scalar value.
                $row[] = $value instanceof \BackedEnum ? $value->value : $value;
            }
        }

        return $row;
    }

    /**
     * Gap sequence over the sheet's row set. First of the year has no
     * predecessor → null (renders '-'), so the column's average equals
     * span/(n-1) — the dashboard widgets' Avg MTBF. The old Jan-1
     * dayOfYear anchor made the column mean ≠ every span-based number
     * next to it (owner report 2026-09-30).
     */
    private function computeMtbfForIncident($incident): ?int
    {
        $year = $incident->incident_date->year;
        $key = "export_{$this->title}_{$year}";

        if (! isset($this->mtbfCache[$key])) {
            $query = \App\Models\Incident::whereYear('incident_date', $year)
                ->whereIn('severity', Severity::METRIC_ELIGIBLE)
                ->orderBy('incident_date')->orderBy('id');

            // Issues tab uses Issue classification; all others exclude Issues
            if ($this->title === 'All Issues') {
                $query->where('classification', IncidentClassification::Issue->value);
            } else {
                $query->where('classification', '!=', IncidentClassification::Issue->value);
            }

            match ($this->title) {
                'On Going' => $query->where('incident_status', '!=', IncidentStatus::Completed->value),
                'Completed Cases' => $query->where('incident_status', IncidentStatus::Completed->value),
                'Recovered Cases' => $query->where('recovered_fund', '>', 0),
                'P4 Incidents' => $query->where('severity', Severity::P4->value),
                'Non-Tech Incidents' => $query->where('incident_type', IncidentType::NonTech->value),
                'Fund Loss' => $query->where('fund_status', FundStatus::ConfirmedLoss->value),
                'Potential Recovery' => $query->where('fund_status', FundStatus::PotentialRecovery->value),
                'Fully Recovered' => $query->where('fund_status', FundStatus::FullyRecovered->value),
                'Non Tech Loss' => $query->where('fund_status', FundStatus::NonTechLoss->value),
                'Non Fund Loss' => $query->where('fund_status', FundStatus::NonFundLoss->value),
                default => null,
            };

            $incidents = $query->get(['id', 'incident_date']);
            $this->mtbfCache[$key] = [];
            foreach ($incidents as $i => $inc) {
                $this->mtbfCache[$key][$inc->id] = $i === 0
                    ? null
                    : (int) $incidents[$i - 1]->incident_date->startOfDay()
                        ->diffInDays($inc->incident_date->startOfDay());
            }
        }

        return $this->mtbfCache[$key][$incident->id] ?? null;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Calculate stats for this specific sheet
                $query = $this->query->clone();
                $totalCases = $query->count();

                // MTTR average (exclude fund loss incidents with negative values).
                // No positive (minutes) MTTR at all → no data, render '-' like the column's nulls.
                // BUG-021: avg() is a decimal-string on MySQL — cast before rounding, under strict_types.
                $avgMttrRaw = $query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '>=', 0)->avg('mttr');
                $avgMttr = $avgMttrRaw === null ? '-' : round((float) $avgMttrRaw, 2);

                // Avg MTBF = mean of the MTBF column AS DISPLAYED (first-of-year
                // rows render '-' and drop out) — the bottom must equal the
                // column above it; for a single-year set that is exactly the
                // widgets' span/(n-1).
                $mtbfValues = $query->clone()->get()
                    ->map(fn ($i) => $this->computeMtbfForIncident($i))
                    ->filter(fn ($v) => $v !== null);
                $avgMtbf = $mtbfValues->isEmpty() ? 0 : round((float) $mtbfValues->avg(), 3);

                $this->stats = [
                    'totalCases' => $totalCases,
                    'avgMttr' => $avgMttr,
                    'avgMtbf' => $avgMtbf,
                    'totalPotentialFundLoss' => (float) $query->sum('potential_fund_loss'),
                    'totalFundLoss' => (float) $query->sum('fund_loss'),
                    'totalRecoveredFund' => (float) $query->sum('recovered_fund'),
                ];

                $lastDataRow = $sheet->getHighestRow();
                $lastDataColumn = $sheet->getHighestDataColumn();
                $fullDataRange = 'A1:'.$lastDataColumn.$lastDataRow;

                $sheet->getStyle($fullDataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $headerRange = 'A1:'.$lastDataColumn.'1';
                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFEB9C');

                for ($row = 2; $row <= $lastDataRow; $row++) {
                    if ($row % 2 == 0) {
                        $sheet->getStyle('A'.$row.':'.$lastDataColumn.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEBF7');
                    }
                }

                $sheet->getStyle($fullDataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                // Fund columns stay raw floats; Rupiah is the native number format.
                // ($lastDataRow is the pre-summary table end — summary block not yet written.)
                foreach (IdrFormat::letters($this->columnNames) as $letter) {
                    $sheet->getStyle("{$letter}2:{$letter}{$lastDataRow}")
                        ->getNumberFormat()->setFormatCode(IdrFormat::FORMAT);
                }

                $summaryStartRow = $lastDataRow + 2;
                $sheet->setCellValue("A{$summaryStartRow}", 'Summary For This Sheet');
                $sheet->getStyle("A{$summaryStartRow}")->getFont()->setBold(true);
                $sheet->getStyle("A{$summaryStartRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $summaryHeaderRow = $summaryStartRow + 1;
                $summaryHeaders = ['Total Cases', 'Avg MTTR', 'Avg MTBF', 'Total Potential Loss', 'Total Actual Loss', 'Total Recovered'];
                $sheet->fromArray($summaryHeaders, null, "A{$summaryHeaderRow}");
                $summaryHeaderRange = "A{$summaryHeaderRow}:F{$summaryHeaderRow}";
                $sheet->getStyle($summaryHeaderRange)->getFont()->setBold(true);
                $sheet->getStyle($summaryHeaderRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFEB9C');
                $sheet->getStyle($summaryHeaderRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $summaryDataRow = $summaryStartRow + 2;
                $summaryData = [
                    $this->stats['totalCases'], $this->stats['avgMttr'], $this->stats['avgMtbf'],
                    $this->stats['totalPotentialFundLoss'], $this->stats['totalFundLoss'], $this->stats['totalRecoveredFund'],
                ];
                $sheet->fromArray($summaryData, null, "A{$summaryDataRow}");
                $summaryDataRange = "A{$summaryDataRow}:F{$summaryDataRow}";
                $sheet->getStyle($summaryDataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                // D/E/F = the three fund totals — raw floats with the Rp number format
                $sheet->getStyle("D{$summaryDataRow}:F{$summaryDataRow}")->getNumberFormat()->setFormatCode(IdrFormat::FORMAT);

                $summaryRange = "A{$summaryHeaderRow}:F{$summaryDataRow}";
                $sheet->getStyle($summaryRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            },
        ];
    }
}
