<?php

namespace App\Observers;

use App\Models\Incident;
use App\Models\Label;
use App\Notifications\AssignedAsPicNotification;
use App\Notifications\IncidentStatusChanged;
use App\Notifications\IncidentUpdated;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class IncidentObserver
{
    /**
     * Handle the Incident "created" event.
     */
    public function created(Incident $incident): void
    {
        $this->flushIncidentCache();
        $this->autoLabel($incident);
        $this->calculateMetrics($incident);
        $this->updateAdjacentIncidentMetrics($incident);

        // Notify PIC if assigned during creation
        if ($incident->pic_id) {
            $incident->pic->notify(new AssignedAsPicNotification($incident));
        }
    }

    /**
     * Handle the Incident "updated" event.
     */
    public function updated(Incident $incident): void
    {
        // Track changes for notification
        $changes = [];
        $notifiableFields = [
            'title' => 'Title',
            'severity' => 'Severity',
            'incident_status' => 'Status',
            'incident_type' => 'Type',
            'incident_source' => 'Source',
            'summary' => 'Summary',
            'root_cause' => 'Root Cause',
            'fund_loss' => 'Fund Loss',
        ];

        foreach ($notifiableFields as $field => $label) {
            if ($incident->isDirty($field)) {
                $oldValue = $incident->getOriginal($field);
                $newValue = $incident->$field;

                // Format dates and special values
                if ($oldValue instanceof Carbon) {
                    $oldValue = $oldValue->format('Y-m-d H:i');
                }
                if ($newValue instanceof Carbon) {
                    $newValue = $newValue->format('Y-m-d H:i');
                }

                $changes[$label] = [
                    'from' => $oldValue,
                    'to' => $newValue,
                ];
            }
        }

        // Handle PIC assignment change
        if ($incident->isDirty('pic_id') && $incident->pic_id) {
            $incident->pic->notify(new AssignedAsPicNotification($incident));
        }

        $this->flushIncidentCache();

        if ($incident->isDirty('summary') || $incident->isDirty('root_cause')) {
            $this->autoLabel($incident);
        }

        if ($incident->isDirty('incident_date') || $incident->isDirty('stop_bleeding_at')) {
            $this->calculateMetrics($incident);
            $this->updateAdjacentIncidentMetrics($incident);
        }

        // Handle status change notification
        if ($incident->isDirty('incident_status')) {
            $oldStatus = $incident->getOriginal('incident_status');
            $newStatus = $incident->incident_status;

            if ($incident->pic && $oldStatus && $newStatus) {
                $currentUser = auth()->user();
                if (! $currentUser || $currentUser->id !== $incident->pic_id) {
                    $incident->pic->notify(new IncidentStatusChanged($incident, $oldStatus, $newStatus));
                }
            }
        }

        // Send general update notification if there are meaningful changes
        if ($incident->wasChanged() && ! empty($changes) && $incident->pic) {
            $currentUser = auth()->user();
            if ($currentUser && $currentUser->id !== $incident->pic_id) {
                $incident->pic->notify(new IncidentUpdated($incident, $changes));
            }
        }
    }

    /**
     * Calculate MTTR and MTBF for an incident.
     *
     * MTTR (Mean Time To Resolve):
     *   - Fund loss incidents: stored in DAYS (legal/recovery processes take longer)
     *   - Other incidents: stored in MINUTES
     *   - Note: Fund loss MTTR is stored as negative value to indicate days
     *
     * MTBF (Mean Time Between Failures): Days from previous incident or Jan 1st
     */
    private function calculateMetrics(Incident $incident): void
    {
        // Calculate MTTR
        if ($incident->stop_bleeding_at) {
            if ($incident->hasFundLoss()) {
                // Fund loss incidents - store as DAYS (negative value indicates days)
                $days = $incident->incident_date->diffInDays($incident->stop_bleeding_at);
                $incident->mttr = -$days; // Negative to indicate days vs minutes
            } else {
                // Regular incidents - store as MINUTES
                $incident->mttr = $incident->incident_date->diffInMinutes($incident->stop_bleeding_at);
            }
        } else {
            $incident->mttr = null;
        }

        // Calculate MTBF using optimized query with index
        $year = $incident->incident_date->year;
        $previousIncident = Incident::where('incident_date', '<', $incident->incident_date)
            ->whereYear('incident_date', $year)
            ->orderBy('incident_date', 'desc')
            ->first();

        if ($previousIncident) {
            // Days from previous incident
            $incident->mtbf = $previousIncident->incident_date->diffInDays($incident->incident_date);
        } else {
            // First incident of the year - calculate from Jan 1st
            $yearStart = Carbon::create($year, 1, 1, 0, 0, 0);
            $incident->mtbf = $yearStart->diffInDays($incident->incident_date);
        }

        $incident->saveQuietly();
    }

    /**
     * Update MTBF and MTTR for adjacent incidents when one is created/updated.
     */
    private function updateAdjacentIncidentMetrics(Incident $incident): void
    {
        $year = $incident->incident_date->year;

        // Update next incident's MTBF
        $nextIncident = Incident::where('incident_date', '>', $incident->incident_date)
            ->whereYear('incident_date', $year)
            ->orderBy('incident_date', 'asc')
            ->first();

        if ($nextIncident) {
            // Update MTBF - days from this incident to next
            $nextIncident->mtbf = $incident->incident_date->diffInDays($nextIncident->incident_date);

            // Update MTTR
            if ($nextIncident->stop_bleeding_at) {
                if ($nextIncident->hasFundLoss()) {
                    // Fund loss - store as DAYS (negative)
                    $days = $nextIncident->incident_date->diffInDays($nextIncident->stop_bleeding_at);
                    $nextIncident->mttr = -$days;
                } else {
                    // Regular - store as MINUTES
                    $nextIncident->mttr = $nextIncident->incident_date->diffInMinutes($nextIncident->stop_bleeding_at);
                }
            } else {
                $nextIncident->mttr = null;
            }

            $nextIncident->saveQuietly();
        }
    }

    /**
     * Auto-label incident based on summary and root cause.
     */
    private function autoLabel(Incident $incident): void
    {
        $allLabels = Cache::remember('labels', 3600, function () {
            return Label::all();
        });

        $textBlock = strtolower($incident->summary.' '.$incident->root_cause);
        $matchedLabelIds = [];

        foreach ($allLabels as $label) {
            if (preg_match("/\b".preg_quote(strtolower($label->name), '/')."\b/", $textBlock)) {
                $matchedLabelIds[] = $label->id;
            }
        }

        if (! empty($matchedLabelIds)) {
            $incident->labels()->syncWithoutDetaching($matchedLabelIds);
        }
    }

    /**
     * Flush incident cache with fine-grained keys.
     * Note: Since we're using plain Cache::remember() without tags,
     * we rely on the 60-minute TTL for automatic cache expiration.
     * For immediate invalidation, individual cache keys can be forgotten
     * if the cache key pattern is known.
     */
    private function flushIncidentCache(): void
    {
        // Forget known static cache keys
        Cache::forget('incidents.stats');
        Cache::forget('labels');

        // Dynamic cache keys (incidents.{hash}) will expire after 60-minute TTL
        // For immediate invalidation of all incident caches, you would need
        // to track active cache keys or use a cache versioning strategy.
        // Given the TTL is relatively short (60 minutes), we rely on natural expiration.
    }

    /**
     * Handle the Incident "deleted" event.
     */
    public function deleted(Incident $incident): void
    {
        $this->flushIncidentCache();
    }

    /**
     * Handle the Incident "restored" event.
     */
    public function restored(Incident $incident): void
    {
        $this->flushIncidentCache();
    }

    /**
     * Handle the Incident "force deleted" event.
     */
    public function forceDeleted(Incident $incident): void
    {
        $this->flushIncidentCache();
    }
}
