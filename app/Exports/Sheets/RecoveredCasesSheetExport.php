<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

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

class RecoveredCasesSheetExport implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithTitle
{
    private $query;

    private $title;

    public function __construct($query, string $title)
    {
        $this->query = $query;
        $this->title = $title;
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
        return ['Incident ID', 'Name', 'Recovered'];
    }

    public function map($incident): array
    {
        // Raw float + native Rp format (applied in AfterSheet), so the
        // Recovered cells stay summable.
        return [$incident->no, $incident->title, (float) $incident->recovered_fund];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $lastDataRow = $sheet->getHighestRow();
                $fullDataRange = 'A1:C'.$lastDataRow;

                $sheet->getStyle($fullDataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $headerRange = 'A1:C1';
                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFEB9C');

                for ($row = 2; $row <= $lastDataRow; $row++) {
                    if ($row % 2 == 0) {
                        $sheet->getStyle('A'.$row.':C'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEBF7');
                    }
                }

                $sheet->getStyle($fullDataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle("C2:C{$lastDataRow}")->getNumberFormat()->setFormatCode(\App\Exports\Concerns\IdrFormat::FORMAT);

                // Total recovered + case count below the rows.
                $summaryStartRow = $lastDataRow + 1;
                $totalRecovered = (float) $this->query->clone()->sum('recovered_fund');
                $totalCases = $this->query->clone()->count();

                $sheet->setCellValue("A{$summaryStartRow}", 'Total Recovered');
                $sheet->setCellValue("B{$summaryStartRow}", $totalRecovered);
                $sheet->getStyle("B{$summaryStartRow}")->getNumberFormat()->setFormatCode(\App\Exports\Concerns\IdrFormat::FORMAT);
                $sheet->setCellValue('A'.($summaryStartRow + 1), 'Total Cases');
                $sheet->setCellValue('B'.($summaryStartRow + 1), $totalCases);

                $summaryRange = "A{$summaryStartRow}:B".($summaryStartRow + 1);
                $sheet->getStyle($summaryRange)->getFont()->setBold(true);
                $sheet->getStyle($summaryRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2EFDA');
                $sheet->getStyle($summaryRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle($summaryRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            },
        ];
    }
}
