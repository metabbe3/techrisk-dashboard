<?php

declare(strict_types=1);
namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

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
