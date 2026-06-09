<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Services\Ai\SimilarIncidentService;
use Illuminate\Console\Command;

class TestSimilarIncidentPipeline extends Command
{
    protected $signature = 'similarity:test {incident_id : The ID of the incident to test} {--verbose : Show full THINK analysis}';

    protected $description = 'Test the 3-phase similar incident pipeline (THINK → FIND → VERIFY → DOUBLE-CHECK)';

    public function handle(SimilarIncidentService $service): int
    {
        $incidentId = $this->argument('incident_id');
        $verbose = $this->option('verbose');

        $incident = Incident::with(['labels', 'actionImprovements'])
            ->select(Incident::EXTENDED_SIMILARITY_COLUMNS)
            ->find($incidentId);

        if (! $incident) {
            $this->error("Incident ID {$incidentId} not found.");

            return 1;
        }

        $this->info('=== Testing Similar Incident Pipeline ===');
        $this->line("Source: [{$incident->no}] {$incident->title}");
        $this->line("Severity: {$incident->severity} | Type: {$incident->incident_type} | Date: {$incident->incident_date?->toDateString()}");
        $this->newLine();

        if (! $service->isAvailable()) {
            $this->error('AI service is not available. Check API key and base URL.');

            return 1;
        }

        $this->info('Running pipeline...');
        $this->newLine();

        $result = $service->analyze($incident);

        if (! $result->success) {
            $this->error("Pipeline failed: {$result->error}");

            return 1;
        }

        // Results
        $this->info('--- RESULTS ---');
        $this->line("Model: {$result->model}");
        $this->line("Candidates found: {$result->candidateCount}");
        $this->line("Verified matches: {$result->verifiedCount}");
        $this->line('Tokens used: '.($result->totalTokens ?? 'N/A'));
        $this->newLine();

        if (empty($result->matches)) {
            $this->warn('No similar incidents found.');

            return 0;
        }

        // Show matches
        $this->info('--- MATCHES ---');
        foreach ($result->matches as $i => $match) {
            $number = $i + 1;
            $similarity = round(($match['similarity'] ?? 0) * 100);
            $matchType = $match['match_type'] ?? 'thematic';
            $doubleChecked = isset($match['double_checked']) ? ' [DOUBLE-CHECKED]' : '';

            $this->line("<fg=cyan>{$number}. [{$match['no']}] {$match['title']}</>");
            $this->line("   Similarity: <fg=green>{$similarity}%</> | Type: {$matchType}{$doubleChecked}");
            $this->line("   Severity: {$match['severity']} | Status: {$match['incident_status']} | Date: {$match['incident_date']}");

            if ($match['summary'] ?? null) {
                $this->line('   Summary: '.\Illuminate\Support\Str::limit($match['summary'], 120));
            }

            if ($match['reasoning'] ?? null) {
                $this->line("   Reasoning: {$match['reasoning']}");
            }

            $dimensions = $match['dimensions'] ?? [];
            if (! empty($dimensions)) {
                $dimStr = collect($dimensions)
                    ->map(fn ($v, $k) => "{$k}: ".round($v * 100).'%')
                    ->implode(' | ');
                $this->line("   Dimensions: {$dimStr}");
            }

            $this->newLine();
        }

        // Verbose: show THINK analysis
        if ($verbose && $result->thinkAnalysis) {
            $this->info('--- THINK PHASE ANALYSIS ---');
            $thinkData = json_decode($result->thinkAnalysis, true);
            if ($thinkData) {
                $this->line('Failure Mode: '.($thinkData['failure_mode'] ?? 'N/A'));
                $this->line('Affected Systems: '.implode(', ', $thinkData['affected_systems'] ?? []));
                $this->line('Severity Assessment: '.($thinkData['severity_assessment'] ?? 'N/A'));
                $this->newLine();

                if (! empty($thinkData['comparison_dimensions'])) {
                    $this->line('Comparison Dimensions (ranked):');
                    foreach ($thinkData['comparison_dimensions'] as $dim) {
                        $this->line("  - {$dim['dimension']} ({$dim['relevance']}): {$dim['why']}");
                    }
                    $this->newLine();
                }

                if (! empty($thinkData['search_hints'])) {
                    $this->line('Search Hints:');
                    foreach ($thinkData['search_hints'] as $type => $hints) {
                        $hintStr = is_array($hints) ? implode(', ', $hints) : $hints;
                        $this->line("  - {$type}: {$hintStr}");
                    }
                }
            } else {
                $this->line($result->thinkAnalysis);
            }
        }

        return 0;
    }
}
