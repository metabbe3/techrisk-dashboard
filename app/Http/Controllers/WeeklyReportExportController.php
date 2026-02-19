<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WeeklyReportExportController extends Controller
{
    /**
     * Export weekly report to Excel.
     */
    public function __invoke(Request $request, int $year): StreamedResponse
    {
        // Check if user can access dashboard
        if (!Auth::check() || !Auth::user()->can('access dashboard')) {
            abort(403);
        }

        // Get weekly data
        $weeklyData = $this->getWeeklyData($year);
        $totalOpen = collect($weeklyData)->sum('incident_open');
        $totalClosed = collect($weeklyData)->sum('incident_closed');
        $grandTotal = collect($weeklyData)->sum('total');

        $filename = 'weekly_report_' . $year . '_' . date('Y-m-d') . '.xlsx';

        return Response::streamDownload(function () use ($weeklyData, $totalOpen, $totalClosed, $grandTotal, $year) {
            // Use PhpSpreadsheet if available, otherwise fallback to CSV
            if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
                $this->exportWithPhpSpreadsheet($weeklyData, $totalOpen, $totalClosed, $grandTotal, $year);
            } else {
                $this->exportAsCsv($weeklyData, $totalOpen, $totalClosed, $grandTotal, $year);
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Get weekly data for the given year.
     */
    protected function getWeeklyData(int $year): array
    {
        $currentDate = now();
        $weekData = [];

        // Get custom Friday-Thursday weeks for the selected year
        $weeks = $this->getCustomWeeks($year);

        // Load all incidents for the year in a single query
        $allIncidents = Incident::where('classification', 'Incident')
            ->whereYear('incident_date', $year)
            ->get();

        foreach ($weeks as $weekNumber => $dateRange) {
            // Skip future weeks
            if ($dateRange['start']->gt($currentDate)) {
                continue;
            }

            // Filter from already-loaded collection
            $weekStart = $dateRange['start']->copy()->startOfDay();
            $weekEnd = $dateRange['end']->copy()->endOfDay();

            $incidents = $allIncidents->filter(function ($incident) use ($weekStart, $weekEnd) {
                return $incident->incident_date->between($weekStart, $weekEnd);
            });

            $openCount = $incidents->whereIn('incident_status', ['Open', 'In progress', 'Finalization'])->count();
            $closedCount = $incidents->where('incident_status', 'Completed')->count();
            $totalCount = $incidents->count();

            $weekData[] = (object) [
                'week' => "W{$weekNumber}",
                'date_range' => $dateRange['start']->format('M j').' - '.$dateRange['end']->format('M j, Y'),
                'incident_open' => $openCount,
                'incident_closed' => $closedCount,
                'total' => $totalCount,
            ];
        }

        return $weekData;
    }

    /**
     * Get custom Friday-Thursday weeks for a given year.
     * Week 1: January 1st to first Thursday
     * Week 2 onwards: Friday to Thursday
     */
    protected function getCustomWeeks(int $year): array
    {
        $weeks = [];
        $yearStart = \Carbon\Carbon::create($year, 1, 1)->startOfDay();

        // Week 1: Always starts from January 1st
        $week1End = $yearStart->copy();
        while ($week1End->dayOfWeek !== 4) { // 4 = Thursday
            $week1End->addDay();
        }

        $weeks[1] = [
            'start' => $yearStart->copy(),
            'end' => $week1End->copy(),
        ];

        // Find the first Friday after Week 1 ends
        $currentFriday = $week1End->copy()->addDay();
        while ($currentFriday->dayOfWeek !== 5) { // 5 = Friday
            $currentFriday->addDay();
        }

        // Week 2 onwards: Friday to Thursday (7 days each)
        $weekNumber = 2;
        while ($currentFriday->year === $year) {
            $weekStart = $currentFriday->copy();
            $weekEnd = $currentFriday->copy()->addDays(6);

            $weeks[$weekNumber] = [
                'start' => $weekStart,
                'end' => $weekEnd,
            ];

            $currentFriday->addWeek();
            $weekNumber++;

            if ($weekNumber > 53) {
                break;
            }
        }

        return $weeks;
    }

    /**
     * Export using PhpSpreadsheet.
     */
    protected function exportWithPhpSpreadsheet(array $weeklyData, int $totalOpen, int $totalClosed, int $grandTotal, int $year): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Title
        $sheet->setCellValue('A1', 'Weekly Incident Report - ' . $year);
        $sheet->mergeCells('A1:E1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        // Summary stats
        $sheet->setCellValue('A3', 'Summary');
        $sheet->getStyle('A3')->getFont()->setBold(true);
        $sheet->setCellValue('A4', 'Total Open');
        $sheet->setCellValue('B4', $totalOpen);
        $sheet->setCellValue('A5', 'Total Closed');
        $sheet->setCellValue('B5', $totalClosed);
        $sheet->setCellValue('A6', 'Grand Total');
        $sheet->setCellValue('B6', $grandTotal);

        // Headers
        $sheet->setCellValue('A8', 'Week');
        $sheet->setCellValue('B8', 'Date Range');
        $sheet->setCellValue('C8', 'Incident Open');
        $sheet->setCellValue('D8', 'Incident Closed');
        $sheet->setCellValue('E8', 'Total');

        // Make headers bold
        $sheet->getStyle('A8:E8')->getFont()->setBold(true);

        // Data rows
        $row = 9;
        foreach ($weeklyData as $data) {
            $sheet->setCellValue('A' . $row, $data->week);
            $sheet->setCellValue('B' . $row, $data->date_range);
            $sheet->setCellValue('C' . $row, $data->incident_open);
            $sheet->setCellValue('D' . $row, $data->incident_closed);
            $sheet->setCellValue('E' . $row, $data->total);
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Export as CSV (fallback).
     */
    protected function exportAsCsv(array $weeklyData, int $totalOpen, int $totalClosed, int $grandTotal, int $year): void
    {
        $output = fopen('php://output', 'w');

        // Add BOM for UTF-8
        fprintf($output, "\xEF\xBB\xBF");

        // Title
        fputcsv($output, ['Weekly Incident Report - ' . $year]);

        // Summary
        fputcsv($output, ['']);
        fputcsv($output, ['Summary']);
        fputcsv($output, ['Total Open', $totalOpen]);
        fputcsv($output, ['Total Closed', $totalClosed]);
        fputcsv($output, ['Grand Total', $grandTotal]);

        // Headers
        fputcsv($output, ['']);
        fputcsv($output, ['Week', 'Date Range', 'Incident Open', 'Incident Closed', 'Total']);

        // Data
        foreach ($weeklyData as $data) {
            fputcsv($output, [
                $data->week,
                $data->date_range,
                $data->incident_open,
                $data->incident_closed,
                $data->total,
            ]);
        }

        fclose($output);
    }
}
