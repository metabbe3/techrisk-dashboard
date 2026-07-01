<?php

namespace App\Console\Commands;

use App\Enums\IncidentClassification;
use App\Jobs\DetectRecurrenceJob;
use App\Models\Incident;
use Illuminate\Console\Command;

class BackfillRecurrenceData extends Command
{
    protected $signature = 'incidents:backfill-recurrence
                            {--limit=0 : Max incidents to process (0 = all)}
                            {--delay=5 : Seconds between job dispatches}
                            {--force : Re-analyze even if already has recurrence_data}';

    protected $description = 'Backfill recurrence_data for past incidents that haven\'t been analyzed yet';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $delay = (int) $this->option('delay');
        $force = $this->option('force');

        $query = Incident::whereIn('classification', [IncidentClassification::Incident->value, IncidentClassification::Issue->value])
            ->orderByDesc('incident_date');

        if (! $force) {
            $query->whereNull('recurrence_data');
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No incidents to process.');

            return self::SUCCESS;
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $incidents = $query->get(['id', 'no', 'title']);
        $processed = 0;

        $this->info("Found {$total} incident(s) to analyze".($limit > 0 ? " (processing {$incidents->count()})" : ''));
        $this->newLine();

        $bar = $this->output->createProgressBar($incidents->count());

        foreach ($incidents as $incident) {
            if ($force) {
                $incident->recurrence_data = null;
                $incident->saveQuietly();
            }

            DetectRecurrenceJob::dispatch($incident)->delay(now()->addSeconds($delay * $processed));
            $processed++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Dispatched {$processed} recurrence detection job(s) to the queue.");
        $this->comment('Run `php artisan queue:work` to process them.');

        return self::SUCCESS;
    }
}
