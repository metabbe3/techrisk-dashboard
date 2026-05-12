<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class AnalyticsExport implements WithMultipleSheets
{
    use Exportable;

    public function __construct(
        private array $rawDataSets,
        private string $metricLabel,
        private string $dimensionLabel,
    ) {}

    public function sheets(): array
    {
        $sheets = [
            new AnalyticsDataSheet($this->rawDataSets[0] ?? [], $this->metricLabel, $this->dimensionLabel, 'Primary Data'),
        ];

        if (count($this->rawDataSets) > 1) {
            $sheets[] = new AnalyticsDataSheet($this->rawDataSets[1], $this->metricLabel, $this->dimensionLabel, 'Comparison Data');
        }

        return $sheets;
    }
}

class AnalyticsDataSheet implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(
        private array $rawData,
        private string $metricLabel,
        private string $dimensionLabel,
        private string $title,
    ) {}

    public function collection()
    {
        return collect($this->rawData)->map(fn ($row) => [
            $row['label'] ?? '',
            $row['value'] ?? 0,
        ]);
    }

    public function headings(): array
    {
        return [$this->dimensionLabel, $this->metricLabel];
    }

    public function title(): string
    {
        return $this->title;
    }
}
