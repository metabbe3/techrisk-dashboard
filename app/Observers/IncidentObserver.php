<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Events\IncidentCreatedEvent;
use App\Events\IncidentEscalatedEvent;
use App\Jobs\Ai\DetectSimilarIncidentsJob;
use App\Jobs\CalculateIncidentMetrics;
use App\Jobs\DetectRecurrenceJob;
use App\Models\Incident;
use App\Notifications\IncidentStatusChanged;
use App\Notifications\IncidentUpdated;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class IncidentObserver
{
    /**
     * Closure gate (PROJ-011 Wave 2, RD-2): a row cannot move INTO Completed
     * without an RCA (root_cause or root_cause_category) and a stop-bleed
     * time. One guard covers the edit form, update_status, API and imports.
     * Deliberate exception to "observers never block" (documented in
     * CLAUDE.md): without it, bad data silently persists — 28/66 Completed
     * rows were missing RCA (findings.md 2026-10-13). Rows already
     * Completed stay editable; only the transition is gated. 'N/A' in
     * root_cause passes — a human can override deliberately.
     */
    public function saving(Incident $incident): void
    {
        $becomingCompleted = $incident->incident_status === IncidentStatus::Completed
            && $incident->isDirty('incident_status');

        if (! $becomingCompleted) {
            return;
        }

        $hasRca = ! blank($incident->root_cause) || ! empty($incident->root_cause_category);

        if (! $hasRca || $incident->stop_bleeding_at === null) {
            throw ValidationException::withMessages([
                'incident_status' => 'Cannot complete: '
                    .($incident->stop_bleeding_at === null ? 'stop bleeding time' : 'root cause')
                    .' is still missing. Fill it in before completing.',
            ]);
        }
    }

    /**
     * Handle the Incident "created" event.
     */
    public function created(Incident $incident): void
    {
        // Dispatch metrics calculation to queue for better performance
        dispatch(new CalculateIncidentMetrics(
            $incident,
            shouldAutoLabel: true,
            shouldUpdateAdjacent: true
        ));

        // Dispatch recurrence detection (delayed to allow categories/labels to be saved)
        DetectRecurrenceJob::dispatch($incident)->delay(now()->addSeconds(30));

        // Auto-detect similar incidents with scores (owner 2026-10-01) — delayed
        // so the save + relations settle; runs the same pipeline as the button.
        DetectSimilarIncidentsJob::dispatch($incident)->delay(now()->addMinutes(2));

        // PIC assignment notifications live in the IncidentPic pivot hooks —
        // a pivot sync fires no model event (multi-PIC, PROJ-010). No admin
        // notification for P1/P2 either (owner rule 2026-10-09: nothing
        // automated goes to admins).

        // Fire event for AI proactive analysis
        event(new IncidentCreatedEvent($incident));

        $this->clearAiContextCache();
    }

    /**
     * Handle the Incident "deleting" event (BEFORE the DB delete — the FK
     * nullOnDelete nulls the subs as part of the DELETE statement, so by
     * "deleted" no rows point at the super any more).
     */
    public function deleting(Incident $incident): void
    {
        // Review I1 (PROJ-022): the FK nullOnDelete happens at the database —
        // no Eloquent events fire on the orphaned subs, so this handler is
        // the ONLY chance to recalc them (each rejoins the count surfaces and
        // its MTBF chain) and bump dashboard_cache_version (via the job).
        // Deleting a record without subs changes nothing structural — the
        // 15-min TTL covers the plain count drop (pre-existing behavior).
        Incident::where('parent_incident_id', $incident->getKey())
            ->get()
            ->each(fn (Incident $sub) => dispatch(new CalculateIncidentMetrics($sub)));
    }

    /**
     * Handle the Incident "updated" event.
     */
    public function updated(Incident $incident): void
    {
        // Relations loaded before the update are stale after save — reload so
        // PIC notifications reach the CURRENT assignees.
        $incident->unsetRelations();
        $pics = $incident->pics->all();
        $currentUser = auth()->user();

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

                if ($oldValue instanceof Carbon) {
                    $oldValue = $oldValue->format('Y-m-d H:i');
                }
                if ($newValue instanceof Carbon) {
                    $newValue = $newValue->format('Y-m-d H:i');
                }
                if ($newValue instanceof \BackedEnum) {
                    $newValue = $newValue->value;
                }
                if ($oldValue instanceof \BackedEnum) {
                    $oldValue = $oldValue->value;
                }

                $changes[$label] = [
                    'from' => $oldValue,
                    'to' => $newValue,
                ];
            }
        }

        // PIC assignment changes are pivot syncs — handled (and notified) by
        // the IncidentPic pivot hooks, not here.

        // Determine if metrics recalculation is needed
        $needsRecalculation = $incident->isDirty('incident_date') || $incident->isDirty('stop_bleeding_at');
        $needsCategoryRecalculation = $incident->isDirty('incident_status') || $incident->isDirty('severity') ||
            $incident->isDirty('incident_type') || $incident->isDirty('fund_status') ||
            $incident->isDirty('recovered_fund') || $incident->isDirty('fund_loss') ||
            $incident->isDirty('potential_fund_loss') || $incident->isDirty('classification') ||
            // Linking/unlinking a sub changes what its super counts (PROJ-022
            // roll-up) — must recalc or caches serve stale totals until TTL
            // (Edit-Safety rule 2).
            $incident->isDirty('parent_incident_id');
        // Detection fields feed the governance KPI caches (keyed on
        // dashboard_cache_version) — a change must bump it or they serve
        // stale numbers until TTL (Edit-Safety rule 2).
        $needsCacheBump = $incident->isDirty(['detected_via', 'failed_control', 'detection_delay_days', 'start_time_known']);
        $needsAutoLabel = $incident->isDirty('summary') || $incident->isDirty('root_cause');

        // Dispatch metrics calculation to queue if needed
        if ($needsRecalculation || $needsCategoryRecalculation || $needsCacheBump || $needsAutoLabel) {
            // getOriginal() returns the EnumCast instance — the job wants the string value.
            $previousClassification = $incident->isDirty('classification')
                ? $incident->getOriginal('classification')?->value
                : null;

            dispatch(new CalculateIncidentMetrics(
                $incident,
                shouldAutoLabel: $needsAutoLabel,
                shouldUpdateAdjacent: $needsRecalculation || $incident->isDirty('classification'),
                previousClassification: $previousClassification
            ));
        }

        // Dispatch recurrence detection when categories change and not yet analyzed
        if ($incident->wasChanged(['root_cause_category', 'business_category', 'responsible_team'])
            && $incident->recurrence_data === null) {
            DetectRecurrenceJob::dispatch($incident);
        }

        // Handle status change notification — all PICs get it, except the
        // one doing the change.
        if ($incident->isDirty('incident_status')) {
            $oldStatus = $incident->getOriginal('incident_status')?->value;
            $newStatus = $incident->incident_status->value;

            if ($oldStatus && $newStatus) {
                foreach ($pics as $pic) {
                    if (! $currentUser || $currentUser->id !== $pic->id) {
                        $pic->notify(new IncidentStatusChanged($incident, $oldStatus, $newStatus));
                    }
                }
            }
        }

        // Send general update notification if there are meaningful changes
        if ($incident->wasChanged() && ! empty($changes)) {
            foreach ($pics as $pic) {
                if (! $currentUser || $currentUser->id !== $pic->id) {
                    $pic->notify(new IncidentUpdated($incident, $changes));
                }
            }
        }

        // Fire event for severity escalation to P1/P2
        if ($incident->isDirty('severity') && in_array($incident->severity, [Severity::P1, Severity::P2])) {
            event(new IncidentEscalatedEvent($incident, $incident->getOriginal('severity')));
        }

        $this->clearAiContextCache();
    }

    /**
     * AI chat context caches 5-minute aggregates; without this the chat
     * answers with stale counts right after an incident is added/edited.
     */
    private function clearAiContextCache(): void
    {
        app(\App\Services\Ai\ChatContextService::class)->clearDataCache();
    }
}
