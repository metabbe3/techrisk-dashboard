<?php

namespace App\Console\Commands;

use App\Models\Incident;
use Carbon\Carbon;
use Illuminate\Console\Command;

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
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Recalculating incident metrics (MTTR & MTBF)...');

        // Build query
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

        $mttrUpdated = 0;
        $mtbfUpdated = 0;
        $debugCount = 0;
        $bar = $this->output->createProgressBar($incidents->count());

        foreach ($incidents as $incident) {
            $debugCount++;
            $oldMttr = $incident->mttr;
            $oldMtbfbf = $incident->mtbf;

            // Calculate MTTR
            $this->calculateMttr($incident);

            // Calculate MTBF
            $this->calculateMtbfbf($incident);

            // Calculate category MTBF values
            $this->calculateCategoryMtbf($incident);

            // Debug output for first 10 incidents
            if ($this->option('debug') && $debugCount <= 10) {
                $year = $incident->incident_date->year;
                $previousIncident = Incident::whereYear('incident_date', $year)
                    ->where('classification', $incident->classification) // Same classification only
                    ->where(function ($query) use ($incident) {
                        $query->where('incident_date', '<', $incident->incident_date)
                            ->orWhere(function ($query) use ($incident) {
                                $query->where('incident_date', '=', $incident->incident_date)
                                    ->where('id', '<', $incident->id);
                            });
                    })
                    ->orderBy('incident_date', 'desc')
                    ->orderBy('id', 'desc')
                    ->first();

                $this->newLine();
                $this->line("  [DEBUG #{$debugCount}] {$incident->no} ({$incident->classification}) - {$incident->incident_date->format('Y-m-d H:i:s')}");
                if ($previousIncident) {
                    $daysDiff = abs($incident->incident_date->startOfDay()
                        ->diffInDays($previousIncident->incident_date->startOfDay()));
                    $this->line("    Previous: {$previousIncident->no} ({$previousIncident->classification}) - {$previousIncident->incident_date->format('Y-m-d H:i:s')}");
                    $this->line("    Calendar day diff: {$daysDiff} days");
                    $this->line("    MTBF = {$daysDiff}");
                } else {
                    $yearStart = Carbon::create($year, 1, 1)->startOfDay();
                    $daysDiff = abs($incident->incident_date->startOfDay()
                        ->diffInDays($yearStart));
                    $this->line("    First {$incident->classification} of year {$year}");
                    $this->line("    Days from Jan 1: {$daysDiff} days");
                    $this->line("    MTBF = {$daysDiff}");
                }
                $this->line("    Old MTBF: {$oldMtbfbf} -> New MTBF: {$incident->mtbf}");
            }

            // Check if values changed (use strict comparison with proper null handling)
            $mttrChanged = $oldMttr !== $incident->mttr || ($oldMttr === null && $incident->mttr !== null) || ($oldMttr !== null && $incident->mttr === null);
            $mtbfChanged = $oldMtbfbf !== $incident->mtbf || ($oldMtbfbf === null && $incident->mtbf !== null) || ($oldMtbfbf !== null && $incident->mtbf === null);

            if ($mttrChanged) {
                $mttrUpdated++;
            }
            if ($mtbfChanged) {
                $mtbfUpdated++;
            }

            if ($mttrChanged || $mtbfChanged) {
                if ($this->option('dry-run')) {
                    $this->newLine();
                    $this->line("  [DRY RUN] {$incident->no}:");
                    if ($mttrChanged) {
                        $this->line("    MTTR: {$oldMttr} -> {$incident->mttr}");
                    }
                    if ($mtbfChanged) {
                        $this->line("    MTBF: {$oldMtbfbf} -> {$incident->mtbf}");
                    }
                } else {
                    $incident->saveQuietly();
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No changes were saved.');
            $this->info("Would update MTTR for {$mttrUpdated} incidents.");
            $this->info("Would update MTBF for {$mtbfUpdated} incidents.");
        } else {
            $this->info("Successfully updated MTTR for {$mttrUpdated} incidents.");
            $this->info("Successfully updated MTBF for {$mtbfUpdated} incidents.");
        }

        return self::SUCCESS;
    }

    /**
     * Calculate MTTR for an incident.
     */
    private function calculateMttr(Incident $incident): void
    {
        if (! $incident->stop_bleeding_at) {
            $incident->mttr = null;

            return;
        }

        // Check if should calculate by days based on fund_status
        $calculateByDays = in_array($incident->fund_status, ['Confirmed loss', 'Potential recovery']);

        if ($calculateByDays) {
            // Fund status "Confirmed loss" or "Potential recovery" - store as DAYS (date-only)
            // Add 1 to include both start and end days in the count
            $days = abs($incident->incident_date->startOfDay()
                ->diffInDays($incident->stop_bleeding_at->startOfDay())) + 1;
            $incident->mttr = -$days; // Negative to indicate days vs minutes
        } else {
            // "Non fundLoss" - store as MINUTES
            $incident->mttr = $incident->incident_date->diffInMinutes($incident->stop_bleeding_at);
        }
    }

    /**
     * Calculate MTBF for an incident.
     */
    private function calculateMtbfbf(Incident $incident): void
    {
        $year = $incident->incident_date->year;

        // Find previous incident in the same year AND same classification (Incident vs Issue)
        // The previous incident is the one that comes immediately before the current incident
        // when incidents are sorted by (incident_date, id).
        // We find this by looking for incidents with (date < current_date) OR (date = current_date AND id < current_id)
        // and taking the maximum such incident by (date, id).
        // EXCLUDE "Non Incident" severity from overall MTBF calculation
        $previousIncident = Incident::whereYear('incident_date', $year)
            ->where('classification', $incident->classification) // Same classification only
            ->where('severity', '!=', 'Non Incident') // Exclude Non Incident from overall MTBF
            ->where(function ($query) use ($incident) {
                $query->where('incident_date', '<', $incident->incident_date)
                    ->orWhere(function ($query) use ($incident) {
                        $query->where('incident_date', '=', $incident->incident_date)
                            ->where('id', '<', $incident->id);
                    });
            })
            ->orderBy('incident_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($previousIncident) {
            // MTBF = calendar day difference (ignoring time)
            $incident->mtbf = abs($incident->incident_date->startOfDay()
                ->diffInDays($previousIncident->incident_date->startOfDay()));
        } else {
            // First incident of the year - calculate from Jan 1st
            $yearStart = Carbon::create($year, 1, 1)->startOfDay();
            $incident->mtbf = abs($incident->incident_date->startOfDay()
                ->diffInDays($yearStart));
        }
    }

    /**
     * Calculate MTBF for all category types.
     * Each category MTBF is calculated independently (only looks at incidents in that category).
     */
    private function calculateCategoryMtbf(Incident $incident): void
    {
        $year = $incident->incident_date->year;

        // Define categories and their conditions
        $categories = [
            'completed' => ['incident_status' => 'Completed'],
            'p4' => ['severity' => 'P4'],
            'non_tech' => ['incident_type' => 'Non-tech'],
            'fund_loss' => ['fund_status' => 'Confirmed loss'],
            'non_fund_loss' => ['fund_status' => 'Non fundLoss'],
            'potential_recovery' => ['fund_status' => 'Potential recovery'],
            'non_incident' => ['severity' => 'Non Incident'],
        ];

        // Process each category
        foreach ($categories as $key => $condition) {
            $previousIncident = Incident::whereYear('incident_date', $year)
                ->where('classification', $incident->classification)
                ->where($condition)
                ->where(function ($query) use ($incident) {
                    $query->where('incident_date', '<', $incident->incident_date)
                        ->orWhere(function ($query) use ($incident) {
                            $query->where('incident_date', '=', $incident->incident_date)
                                ->where('id', '<', $incident->id);
                        });
                })
                ->orderBy('incident_date', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            if ($previousIncident) {
                $incident->{"mtbf_{$key}"} = abs($incident->incident_date->startOfDay()
                    ->diffInDays($previousIncident->incident_date->startOfDay()));
            } else {
                $yearStart = Carbon::create($year, 1, 1)->startOfDay();
                $incident->{"mtbf_{$key}"} = abs($incident->incident_date->startOfDay()
                    ->diffInDays($yearStart));
            }
        }

        // Special handling for 'recovered' category (recovered_fund > 0)
        $previousRecovered = Incident::whereYear('incident_date', $year)
            ->where('classification', $incident->classification)
            ->where('recovered_fund', '>', 0)
            ->where(function ($query) use ($incident) {
                $query->where('incident_date', '<', $incident->incident_date)
                    ->orWhere(function ($query) use ($incident) {
                        $query->where('incident_date', '=', $incident->incident_date)
                            ->where('id', '<', $incident->id);
                    });
            })
            ->orderBy('incident_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($previousRecovered) {
            $incident->mtbf_recovered = abs($incident->incident_date->startOfDay()
                ->diffInDays($previousRecovered->incident_date->startOfDay()));
        } else {
            $yearStart = Carbon::create($year, 1, 1)->startOfDay();
            $incident->mtbf_recovered = abs($incident->incident_date->startOfDay()
                ->diffInDays($yearStart));
        }
    }
}
