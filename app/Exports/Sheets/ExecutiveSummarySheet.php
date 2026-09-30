<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

use App\Exports\Concerns\IdrFormat;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

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
                self::writeKpiCards(
                    $event->sheet->getDelegate(),
                    $this->calc->agg['kpi'],
                    'EXECUTIVE INCIDENT REPORT — '.now()->format('d M Y')
                );
            },
        ];
    }

    /**
     * Banner + the 9 KPI cards at rows 4/5. Shared verbatim by the
     * per-quarter tabs (ExecutiveQuarterSheet), which pass their own banner.
     *
     * @param  array<string, mixed>  $kpi
     */
    public static function writeKpiCards(Worksheet $sheet, array $kpi, string $banner): void
    {
        $sheet->mergeCells('A1:J2');
        $sheet->setCellValue('A1', $banner);
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        self::writeCardRow($sheet, [
            ['Total Cases', $kpi['totalCases']],
            ['Open', $kpi['open']],
            ['Avg MTTR', $kpi['avgMttrMins'] > 0 ? "{$kpi['avgMttrMins']} min" : "{$kpi['avgMttrDays']} days"],
            ['Avg MTBF', "{$kpi['avgMtbf']} days"],
            // Raw floats + native Rp format (E5:G5 below), not pre-formatted
            // strings — keeps the cards summable numbers.
            ['Potential Loss', (float) $kpi['potential']],
            ['Recovered', (float) $kpi['recovered']],
            ['Actual Loss', (float) $kpi['actual']],
            ['Recovery Rate', $kpi['recoveryRate'].'%'],
        ], 4, 5);

        // E5=Potential Loss, F5=Recovered, G5=Actual Loss
        $sheet->getStyle('E5:G5')->getNumberFormat()->setFormatCode(IdrFormat::FORMAT);
    }

    /**
     * One label/value card pair per column at the given rows, with the card
     * styling (label row small caps grey, value row large navy, thin borders
     * through the row below the value).
     *
     * @param  array<int, array{0: string, 1: mixed}>  $cards
     */
    public static function writeCardRow(Worksheet $sheet, array $cards, int $labelRow, int $valueRow): void
    {
        $col = 'A';
        foreach ($cards as [$label, $value]) {
            $sheet->setCellValue("{$col}{$labelRow}", $label);
            $sheet->setCellValue("{$col}{$valueRow}", $value);
            $sheet->getStyle("{$col}{$labelRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '475569']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getStyle("{$col}{$valueRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '1E3A5F']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getStyle("{$col}{$labelRow}:{$col}".($valueRow + 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $col++;
        }
    }
}
