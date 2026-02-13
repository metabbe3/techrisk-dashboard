<?php

namespace App\Console\Commands;

use App\Models\Incident;
use Carbon\Carbon;
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
            $year = $incident->incident_date->year;

            // Calculate MTTR
            if ($incident->stop_bleeding_at) {
                if ($incident->hasFundLoss()) {
                    // Fund loss incidents - store as DAYS (negative value)
                    $days = $incident->incident_date->diffInDays($incident->stop_bleeding_at);
                    $incident->mttr = -$days;
                } else {
                    // Regular incidents - store as MINUTES
                    $incident->mttr = $incident->incident_date->diffInMinutes($incident->stop_bleeding_at);
                }
            } else {
                $incident->mttr = null;
            }

            // Calculate MTBF
            if ($previousIncident && $previousIncident->incident_date->year === $year) {
                // Days from previous incident (same year)
                $incident->mtbf = $previousIncident->incident_date->diffInDays($incident->incident_date);
            } else {
                // First incident of the year - days from Jan 1st
                $yearStart = Carbon::create($year, 1, 1, 0, 0, 0);
                $incident->mtbf = $yearStart->diffInDays($incident->incident_date);
            }

            $incident->saveQuietly();

            $previousIncident = $incident;
        }

        $this->info('Done.');
    }
}
