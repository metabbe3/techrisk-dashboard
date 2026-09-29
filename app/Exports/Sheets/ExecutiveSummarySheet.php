<?php

declare(strict_types=1);
namespace App\Exports\Sheets;

use App\Exports\Sheets\ExecutiveCalcSheet;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

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
