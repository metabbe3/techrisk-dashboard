<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

use App\Exports\Concerns\IdrFormat;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Summary sheet of the Quarterly Report: P1–P4/X1–X4 case counts one line
 * per business category, per root cause category and per quarter, an All
 * line, Avg MTBF and the widget-aligned fund totals (rows precomputed by
 * QuarterlyReportExport — this class only renders).
 *
 * Row kinds passed in:
 *  - ['kind' => 'section', 'label' => string]
 *  - ['kind' => 'count',   'label' => string, 'counts' => [severity => n], 'total' => int]
 *  - ['kind' => 'metric',  'label' => string, 'value' => float, 'idr' => bool]
 */
class QuarterlySummarySheet implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithTitle
{
    public function __construct(protected array $rows) {}

    public function title(): string
    {
        return 'Summary';
    }

    public function headings(): array
    {
        return ['Breakdown', 'P1', 'P2', 'P3', 'P4', 'X1', 'X2', 'X3', 'X4', 'Total'];
    }

    public function collection()
    {
        return collect($this->rows);
    }

    public function map($row): array
    {
        if ($row['kind'] === 'count') {
            return [$row['label'], ...array_values($row['counts']), $row['total']];
        }

        if ($row['kind'] === 'metric') {
            return [$row['label'], $row['value'], null, null, null, null, null, null, null, null];
        }

        return [$row['label'], null, null, null, null, null, null, null, null, null];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                $headerRange = 'A1:J1';
                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFEB9C');
                $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                foreach ($this->rows as $i => $row) {
                    $excelRow = $i + 2; // headings occupy row 1

                    if ($row['kind'] === 'section') {
                        $sheet->getStyle("A{$excelRow}")->getFont()->setBold(true);

                        continue;
                    }

                    if (($row['kind'] === 'metric') && ($row['idr'] ?? false)) {
                        // Per-cell, not WithColumnFormatting: column B also carries
                        // P1 counts in count rows — a column-wide Rp format would
                        // render them as "Rp 3".
                        $sheet->getStyle("B{$excelRow}")->getNumberFormat()->setFormatCode(IdrFormat::FORMAT);
                    }
                }
            },
        ];
    }
}
