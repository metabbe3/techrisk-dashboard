<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

use Illuminate\Database\Eloquent\Builder;
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
            $incident->mttr_formatted,
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
