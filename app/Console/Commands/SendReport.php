<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\IncidentClassification;
use App\Enums\Severity;
use App\Exports\IncidentsExport;
use App\Models\Incident;
use App\Models\ReportTemplate;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;

class SendReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-report {report_template_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate and send a report.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $template = ReportTemplate::find($this->argument('report_template_id'));

        if (! $template) {
            $this->error('Report template not found.');

            return;
        }

        $data = $template->filters;
        $query = Incident::query()->where('classification', '!=', IncidentClassification::Issue->value);

        if ($data['start_date']) {
            $query->where('incident_date', '>=', Carbon::parse($data['start_date']));
        }

        if ($data['end_date']) {
            // DatePicker values are midnight-only — include the final day.
            $query->where('incident_date', '<=', Carbon::parse($data['end_date'])->endOfDay());
        }

        if (! empty($data['incident_types'])) {
            // Filter stores IncidentType enum values (same as Reporting page);
            // incident_type_id is the FK column and never matched them.
            $query->whereIn('incident_type', $data['incident_types']);
        }

        if (! empty($data['statuses'])) {
            $query->whereHas('latestStatusUpdate', function ($q) use ($data) {
                $q->whereIn('status', $data['statuses']);
            });
        }

        if (! empty($data['severities'])) {
            $query->whereIn('severity', $data['severities']);
        }

        $incidents = $query->with('labels')->get();

        $metrics = [];
        if (in_array('total_incidents', $template->metrics)) {
            $metrics['total_incidents'] = $incidents->count();
        }
        // Collection severity attributes are enum instances — METRIC_ELIGIBLE
        // holds strings, so whereIn() never matched (BUG-022 trap); and
        // Outlier-tagged rows leave every MTBF/MTTR average (owner rule
        // 2026-10-01) while staying in total_incidents.
        $metricEligible = fn (Incident $i) => in_array($i->severity?->value, Severity::METRIC_ELIGIBLE, true)
            && ! $i->isOutlier();
        if (in_array('avg_mttr', $template->metrics)) {
            $metrics['avg_mttr'] = $incidents->filter($metricEligible)->where('mttr', '>=', 0)->avg('mttr');
        }
        if (in_array('avg_mtbf', $template->metrics)) {
            $mtbfIncidents = $incidents->filter($metricEligible);
            $mtbfCount = $mtbfIncidents->count();
            $avgMtbf = 0;

            if ($mtbfCount > 1) {
                $sorted = $mtbfIncidents->sortBy('incident_date');
                $minDate = $sorted->first()->incident_date->startOfDay();
                $maxDate = $sorted->last()->incident_date->startOfDay();
                $totalDays = $minDate->diffInDays($maxDate);
                $avgMtbf = round($totalDays / ($mtbfCount - 1), 3);
            }
            $metrics['avg_mtbf'] = $avgMtbf;
        }

        // ponytail: was ->getColumns() — private, fatalled the scheduled command
        $allColumns = (new \App\Filament\Pages\Reporting)->getColumnsFlattened();
        $headings = array_intersect_key($allColumns, array_flip($template->columns));

        $export = new IncidentsExport($incidents, $metrics, $headings);
        $filePath = 'reports/'.$template->name.'_'.time().'.xlsx';
        Excel::store($export, $filePath, 'local');

        Mail::raw('Here is your scheduled report.', function ($message) use ($template, $filePath) {
            $message->to($template->email)
                ->subject('Scheduled Report: '.$template->name)
                ->attach(storage_path('app/'.$filePath));
        });

        $this->info('Report sent successfully.');
    }
}
