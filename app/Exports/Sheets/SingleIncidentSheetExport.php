<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

use App\Enums\Severity;
use App\Exports\Concerns\ComputesMtbfSequence;
use App\Exports\Concerns\IdrFormat;
use App\Exports\Concerns\WritesSeverityFooter;
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
    use ComputesMtbfSequence;
    use WritesSeverityFooter;

    private $query;

    private $title;

    private $stats;

    private $headings;

    private $columnNames;

    public function __construct($query, string $title, array $headings, array $columnNames)
    {
        $this->query = $query;
        $this->title = $title;
        $this->headings = $headings;
        $this->columnNames = $columnNames;
        // The sheet's own (already export-filtered) query scopes the gap
        // sequence — one implementation for every export (owner one-logic
        // rule 2026-11-16). The trait trusts it for classification/fund
        // scope and adds only METRIC_ELIGIBLE + outliers + year + ordering.
        $this->mtbfScope = $query;
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
                // Outlier rows keep their place with a literal instead of a
                // gap value (owner rule 2026-10-01); sequence skips them.
                $row[] = $incident->isOutlier() ? 'Outlier' : ($this->computeMtbfForIncident($incident) ?? '-');
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
     * Gap sequence over the sheet's row set, via the shared
     * ComputesMtbfSequence implementation with the sheet's query as scope —
     * first of the year null → '-' (renders like the Custom preset), so the
     * column's average equals span/(n-1) — the dashboard widgets' Avg MTBF.
     * f_* filters scope the gaps too — excluded rows telescope over (owner
     * report 2026-10-23).
     */
    private function computeMtbfForIncident($incident): ?int
    {
        return $this->mtbfSequenceValue($incident);
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

                // Severity + MTTR + fund counts below the summary block.
                $this->writeSeverityFooter($sheet, $summaryDataRow + 2, $this->query, $this->stats['avgMtbf']);
            },
        ];
    }
}
