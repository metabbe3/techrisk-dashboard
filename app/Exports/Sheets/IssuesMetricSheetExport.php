<?php

namespace App\Exports\Sheets;

use App\Models\Incident;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class IssuesMetricSheetExport implements FromQuery, WithTitle, WithHeadings, WithMapping, ShouldAutoSize, WithEvents
{
    private $query;
    private $title;
    private $metricType; // 'mttr' or 'mtbf'

    public function __construct($query, string $title, string $metricType)
    {
        $this->query = $query;
        $this->title = $title;
        $this->metricType = $metricType;
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
        $metricLabel = $this->metricType === 'mttr' ? 'MTTR (mins)' : 'MTBF (days)';
        return ['Issue Name', 'Type', $metricLabel];
    }

    public function map($incident): array
    {
        $metricValue = $this->metricType === 'mttr' ? $incident->mttr : $incident->mtbf;

        return [
            str_replace('Summary of Incident - ', '', $incident->title),
            $incident->severity,
            $metricValue ?? '-',
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $lastDataRow = $sheet->getHighestRow();
                $lastDataColumn = 'C';
                $fullDataRange = 'A1:' . $lastDataColumn . $lastDataRow;

                $sheet->getStyle($fullDataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $headerRange = 'A1:' . $lastDataColumn . '1';
                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFEB9C');

                for ($row = 2; $row <= $lastDataRow; $row++) {
                    if ($row % 2 == 0) {
                        $sheet->getStyle('A' . $row . ':' . $lastDataColumn . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEBF7');
                    }
                }

                $sheet->getStyle($fullDataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                // Summary
                $summaryStartRow = $lastDataRow + 2;
                $metricLabel = $this->metricType === 'mttr' ? 'Avg MTTR' : 'Avg MTBF';
                $sheet->setCellValue("A{$summaryStartRow}", $metricLabel);
                $sheet->getStyle("A{$summaryStartRow}")->getFont()->setBold(true);
                $sheet->getStyle("A{$summaryStartRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $avgValue = round($this->query->clone()->avg($this->metricType), 2);
                $summaryDataRow = $summaryStartRow + 1;
                $sheet->setCellValue("A{$summaryDataRow}", $avgValue);
                $sheet->getStyle("A{$summaryDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            },
        ];
    }
}
