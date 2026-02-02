<?php

namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class MetricsSheet implements FromCollection, WithHeadings, WithTitle
{
    private $metrics;

    public function __construct(array $metrics)
    {
        $this->metrics = $metrics;
    }

    public function collection()
    {
        $data = [];
        foreach ($this->metrics as $key => $value) {
            $data[] = ['Metric' => $key, 'Value' => $value];
        }

        return collect($data);
    }

    public function headings(): array
    {
        return [
            'Metric',
            'Value',
        ];
    }

    public function title(): string
    {
        return 'Metrics';
    }
}
