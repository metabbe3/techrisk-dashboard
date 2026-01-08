<?php

namespace App\Console\Commands;

use App\Models\Incident;
use Illuminate\Console\Command;

class IncidentsRecalculateMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'incidents:recalculate-metrics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate MTTR and MTBF for all incidents.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Recalculating MTTR and MTBF for all incidents...');

        $incidents = Incident::orderBy('incident_date', 'asc')->get();
        $previousIncident = null;

        foreach ($incidents as $incident) {
            // Calculate MTTR
            if ($incident->stop_bleeding_at) {
                $incident->mttr = $incident->incident_date->diffInMinutes($incident->stop_bleeding_at);
            } else {
                $incident->mttr = null;
            }

            // Calculate MTBF
            if ($previousIncident) {
                $incident->mtbf = $previousIncident->incident_date->diffInDays($incident->incident_date);
            } else {
                $incident->mtbf = null;
            }

            $incident->saveQuietly();

            $previousIncident = $incident;
        }

        $this->info('Done.');
    }
}