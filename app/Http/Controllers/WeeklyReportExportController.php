<?php

namespace App\Http\Controllers;

use App\Services\WeeklyDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WeeklyReportExportController extends Controller
{
    public function __construct(
        private WeeklyDataService $weeklyDataService,
    ) {}

    /**
     * Export weekly report to Excel.
     */
    public function __invoke(Request $request, int $year): StreamedResponse
    {
        // Check if user can access dashboard
        if (! Auth::check() || ! Auth::user()->can('access dashboard')) {
            abort(403);
        }

        // Get weekly data
        $weeklyData = $this->weeklyDataService->getWeeklyData($year);
        $totalOpen = collect($weeklyData)->sum('incident_open');
        $totalClosed = collect($weeklyData)->sum('incident_closed');
        $grandTotal = collect($weeklyData)->sum('total');

        $filename = 'weekly_report_'.$year.'_'.date('Y-m-d').'.xlsx';

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
     * Export using PhpSpreadsheet.
     */
    protected function exportWithPhpSpreadsheet(array $weeklyData, int $totalOpen, int $totalClosed, int $grandTotal, int $year): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // Title
        $sheet->setCellValue('A1', 'Weekly Incident Report - '.$year);
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
            $sheet->setCellValue('A'.$row, $data->week);
            $sheet->setCellValue('B'.$row, $data->date_range);
            $sheet->setCellValue('C'.$row, $data->incident_open);
            $sheet->setCellValue('D'.$row, $data->incident_closed);
            $sheet->setCellValue('E'.$row, $data->total);
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
        fputcsv($output, ['Weekly Incident Report - '.$year]);

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
