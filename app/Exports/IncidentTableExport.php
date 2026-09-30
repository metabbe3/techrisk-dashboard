<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\IncidentClassification;
use App\Exports\Concerns\IdrFormat;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill; // Added

class IncidentTableExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithMapping
{
    protected $incidents;

    protected $stats;

    protected $headings;

    protected $columnNames;

    public function __construct($incidents, $stats, $headings, $columnNames)
    {
        $this->incidents = $incidents;
        $this->stats = $stats;
        $this->headings = $headings;
        $this->columnNames = $columnNames;
    }

    public function collection()
    {
        return $this->incidents;
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
            if ($columnName === 'mttr') {
                $row[] = $incident->mttr_formatted;
            } elseif ($columnName === 'mtbf') {
                $row[] = $this->computeMtbf($incident) ?? '-';
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

    private static array $mtbfCache = [];

    /**
     * Gap sequence, METRIC_ELIGIBLE only. First of the year has no
     * predecessor → null (renders '-') so the column's average equals
     * span/(n-1), matching the bottom summary and the widgets (the old
     * Jan-1 dayOfYear anchor broke that equality — owner report 2026-09-30).
     */
    private function computeMtbf($incident): ?int
    {
        $year = $incident->incident_date->year;
        $key = "export_all_{$year}";

        if (! isset(self::$mtbfCache[$key])) {
            $incidents = \App\Models\Incident::whereYear('incident_date', $year)
                ->where('classification', '!=', IncidentClassification::Issue->value)
                ->whereIn('severity', \App\Enums\Severity::METRIC_ELIGIBLE)
                ->orderBy('incident_date')->orderBy('id')
                ->get(['id', 'incident_date']);

            self::$mtbfCache[$key] = [];
            foreach ($incidents as $i => $inc) {
                self::$mtbfCache[$key][$inc->id] = $i === 0
                    ? null
                    : (int) $incidents[$i - 1]->incident_date->startOfDay()
                        ->diffInDays($inc->incident_date->startOfDay());
            }
        }

        return self::$mtbfCache[$key][$incident->id] ?? null;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastDataRow = $sheet->getHighestDataRow();
                $lastDataColumn = $sheet->getHighestDataColumn();

                // 1. Style Header (Yellow)
                $headerRange = 'A1:'.$lastDataColumn.'1';
                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFFEB9C'); // Yellow
                $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Center align header

                // 2. Zebra Striping for Data Rows (Sky Blue) & Center Alignment
                for ($row = 2; $row <= $lastDataRow; $row++) {
                    $rowRange = 'A'.$row.':'.$lastDataColumn.$row;
                    if ($row % 2 == 0) { // Even rows
                        $sheet->getStyle($rowRange)->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()->setARGB('FFDDEBF7'); // Light Sky Blue
                    }
                    $sheet->getStyle($rowRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                // 3. Add Borders to Main Table
                $dataRange = 'A1:'.$lastDataColumn.$lastDataRow;
                $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                // Fund columns stay raw floats; Rupiah is the native number format.
                foreach (IdrFormat::letters($this->columnNames) as $letter) {
                    $sheet->getStyle("{$letter}2:{$letter}{$lastDataRow}")
                        ->getNumberFormat()->setFormatCode(IdrFormat::FORMAT);
                }

                // --- 4. Summary Row Logic & Styling ---
                $summaryStartRow = $lastDataRow + 2;

                $sheet->setCellValue("A{$summaryStartRow}", 'Summary For Displayed Data');
                $sheet->getStyle("A{$summaryStartRow}")->getFont()->setBold(true);

                $summaryHeaderRow = $summaryStartRow + 1;
                $summaryHeaders = ['Total Cases', 'Avg MTTR', 'Avg MTBF', 'Total Potential Loss', 'Total Actual Loss', 'Total Recovered'];
                $sheet->fromArray($summaryHeaders, null, "A{$summaryHeaderRow}");
                $summaryHeaderRange = "A{$summaryHeaderRow}:F{$summaryHeaderRow}";
                $sheet->getStyle($summaryHeaderRange)->getFont()->setBold(true);
                $sheet->getStyle($summaryHeaderRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFEB9C'); // Yellow
                $sheet->getStyle($summaryHeaderRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Center align summary headers

                $summaryDataRow = $summaryStartRow + 2;
                // Avg MTBF = mean of the MTBF column AS DISPLAYED (first-of-year
                // rows render '-' and drop out) — computed here, not taken from
                // the footer-sourced stats, so the bottom always equals the
                // column above it (single-year sets: exactly span/(n-1)).
                $mtbfValues = $this->incidents
                    ->map(fn ($i) => $this->computeMtbf($i))
                    ->filter(fn ($v) => $v !== null);
                $avgMtbf = $mtbfValues->isEmpty() ? 0 : round((float) $mtbfValues->avg(), 3);
                $summaryData = [
                    $this->stats['totalCases'],
                    $this->stats['avgMttr'],
                    $avgMtbf,
                    $this->stats['totalPotentialFundLoss'],
                    $this->stats['totalFundLoss'],
                    $this->stats['totalRecoveredFund'],
                ];
                $sheet->fromArray($summaryData, null, "A{$summaryDataRow}");
                $summaryDataRange = "A{$summaryDataRow}:F{$summaryDataRow}";
                $sheet->getStyle($summaryDataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Center align summary data
                // D/E/F = the three fund totals — raw floats with the Rp number format
                $sheet->getStyle("D{$summaryDataRow}:F{$summaryDataRow}")->getNumberFormat()->setFormatCode(IdrFormat::FORMAT);

                // Add Borders to Summary
                $summaryRange = "A{$summaryHeaderRow}:F{$summaryDataRow}";
                $sheet->getStyle($summaryRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            },
        ];
    }
}
