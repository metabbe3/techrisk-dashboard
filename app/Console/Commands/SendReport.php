<?php

namespace App\Console\Commands;

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
        $query = Incident::query();

        if ($data['start_date']) {
            $query->where('incident_date', '>=', Carbon::parse($data['start_date']));
        }

        if ($data['end_date']) {
            $query->where('incident_date', '<=', Carbon::parse($data['end_date']));
        }

        if (! empty($data['incident_types'])) {
            $query->whereIn('incident_type_id', $data['incident_types']);
        }

        if (! empty($data['statuses'])) {
            $query->whereHas('latestStatusUpdate', function ($q) use ($data) {
                $q->whereIn('status', $data['statuses']);
            });
        }

        if (! empty($data['severities'])) {
            $query->whereIn('severity', $data['severities']);
        }

        $incidents = $query->get();

        $metrics = [];
        if (in_array('total_incidents', $template->metrics)) {
            $metrics['total_incidents'] = $incidents->count();
        }
        if (in_array('avg_mttr', $template->metrics)) {
            $metrics['avg_mttr'] = $incidents->avg('mttr');
        }
        if (in_array('avg_mtbf', $template->metrics)) {
            $metrics['avg_mtbf'] = $incidents->avg('mtbf');
        }

        $allColumns = array_merge(...array_values((new \App\Filament\Pages\Reporting)->getColumns()));
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
