<?php

namespace App\Exports\Sheets;

use Illuminate\Support\Arr;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class IncidentsSheet implements FromCollection, WithHeadings, WithTitle
{
    private $incidents;

    private $headings;

    public function __construct($incidents, $headings)
    {
        $this->incidents = $incidents;
        $this->headings = $headings;
    }

    public function collection()
    {
        return $this->incidents->map(function ($incident) {
            $row = [];
            foreach (array_keys($this->headings) as $columnKey) {
                $value = Arr::get($incident, $columnKey);
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $row[] = $value;
            }

            return $row;
        });
    }

    public function headings(): array
    {
        return array_values($this->headings);
    }

    public function title(): string
    {
        return 'Incidents';
    }
}
