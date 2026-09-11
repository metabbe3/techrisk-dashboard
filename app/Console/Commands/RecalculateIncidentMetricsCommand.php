<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Services\Metrics\IncidentMetricsCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RecalculateIncidentMetricsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'incidents:recalculate-metrics
        {--year= : Only recalculate for specific year}
        {--force : Force recalculation even if values exist}
        {--dry-run : Show what would be changed without making changes}
        {--debug : Show detailed debug information for first 10 incidents}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate MTTR and MTBF for all incidents';

    /**
     * Execute the console command. Formulas come from IncidentMetricsCalculator —
     * the same ones the CalculateIncidentMetrics job uses, so a sweep can
     * never disagree with per-save recalculation.
     */
    public function handle(): int
    {
        $this->info('Recalculating incident metrics (MTTR & MTBF)...');

        $query = Incident::query();

        if ($year = $this->option('year')) {
            $query->whereYear('incident_date', $year);
            $this->info("Filtering by year: {$year}");
        }

        $incidents = $query->orderBy('incident_date')->get();

        if ($incidents->isEmpty()) {
            $this->warn('No incidents found.');

            return self::SUCCESS;
        }

        $this->info("Processing {$incidents->count()} incidents...");

        $calculator = app(IncidentMetricsCalculator::class);
        $metricColumns = IncidentMetricsCalculator::METRIC_COLUMNS;

        $updated = 0;
        $debugCount = 0;
        $bar = $this->output->createProgressBar($incidents->count());

        foreach ($incidents as $incident) {
            $debugCount++;

            // Compare every metric column — categories can change while the
            // base mttr/mtbf stay the same.
            $before = $incident->only($metricColumns);

            $calculator->computeAll($incident);

            $changed = collect($before)
                ->contains(fn ($value, $column) => $value !== $incident->{$column});

            // Debug output for first 10 incidents
            if ($this->option('debug') && $debugCount <= 10) {
                $this->newLine();
                $this->line("  [DEBUG #{$debugCount}] {$incident->no} ({$incident->classification->value}) - {$incident->incident_date->format('Y-m-d H:i:s')}");
                foreach ($metricColumns as $column) {
                    if ($before[$column] !== $incident->{$column}) {
                        $this->line("    {$column}: {$before[$column]} -> {$incident->{$column}}");
                    }
                }
            }

            if ($changed) {
                $updated++;

                if (! $this->option('dry-run')) {
                    $incident->saveQuietly();
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No changes were saved.');
            $this->info("Would update metrics for {$updated} incidents.");
        } else {
            $this->info("Successfully updated metrics for {$updated} incidents.");
            Cache::increment('dashboard_cache_version');
        }

        return self::SUCCESS;
    }
}
