<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use App\Exports\Sheets\IncidentsSheet;
use App\Exports\Sheets\MetricsSheet;

class IncidentsExport implements WithMultipleSheets
{
    use Exportable;

    protected $incidents;
    protected $metrics;
    protected $headings;

    public function __construct($incidents, $metrics, $headings)
    {
        $this->incidents = $incidents;
        $this->metrics = $metrics;
        $this->headings = $headings;
    }

    public function sheets(): array
    {
        $sheets = [];

        if (!empty($this->metrics)) {
            $sheets[] = new MetricsSheet($this->metrics);
        }

        $sheets[] = new IncidentsSheet($this->incidents, $this->headings);

        return $sheets;
    }
}
