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
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class IncidentObserver
{
    /**
     * S-2 (generateNo race): `no` carries a unique index, but the
     * exists()-check in generateNo() races against a concurrent insert —
     * the loser dies on a duplicate-key QueryException. Regenerate once
     * here, at the single point every write routes through: same draw odds,
     * no user-visible 500. The form field is readOnly, so a collision can
     * only come from this race (or a restore), never a user edit.
     */
    public function creating(Incident $incident): void
    {
        if (! filled($incident->no) || ! Incident::where('no', $incident->no)->exists()) {
            return;
        }

        $prefix = $incident->classification === \App\Enums\IncidentClassification::Issue->value ? 'IS' : 'IN';
        $incident->no = Incident::generateNo($prefix);
    }

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

        $error = Incident::closureGateError(
            $incident->root_cause,
            $incident->root_cause_category,
            $incident->stop_bleeding_at,
        );

        if ($error !== null) {
            throw ValidationException::withMessages(['incident_status' => $error]);
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
            $incident->isDirty('potential_fund_loss') || $incident->isDirty('classification');
        // Detection fields feed the governance KPI caches (keyed on
        // dashboard_cache_version) — a change must bump it or they serve
        // stale numbers until TTL (Edit-Safety rule 2). discovered_at feeds
        // effectiveDetectionLagHours() → GovernanceKpiService lags, so it
        // belongs in this list too.
        $needsCacheBump = $incident->isDirty(['detected_via', 'failed_control', 'detection_delay_days', 'start_time_known', 'discovered_at']);
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

        // Root cause filled/changed later → the original analysis is stale.
        // Re-detect via a force-flagged job (owner 2026-11-18). NO write here:
        // saving inside updated() syncs the model's dirty state before the
        // auditing observer runs, and the root_cause audit entry would come
        // back empty (GovernanceKpiService::rcaWithinFiveDays reads it).
        if ($incident->wasChanged(['root_cause', 'root_cause_category'])) {
            DetectRecurrenceJob::dispatch($incident, force: true);
        } elseif ($incident->wasChanged(['business_category', 'responsible_team'])
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

    /**
     * Handle the Incident "deleted" event. Without it a delete bumped
     * nothing: widgets and exports kept counting the deleted row until the
     * cache TTL, and the NEXT incident's stored MTBF still measured its gap
     * from the deleted one. The job can't be reused — its handle() starts
     * with fresh(), which is null for a deleted row — so the same adjacent
     * repair runs inline (a single metric recompute, no heavy work).
     */
    public function deleted(Incident $incident): void
    {
        $next = Incident::whereYear('incident_date', $incident->incident_date->year)
            ->where('classification', $incident->classification->value)
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->withoutOutliers()
            ->where(function ($query) use ($incident) {
                $query->where('incident_date', '>', $incident->incident_date)
                    ->orWhere(function ($query) use ($incident) {
                        $query->where('incident_date', '=', $incident->incident_date)
                            ->where('id', '>', $incident->id);
                    });
            })
            ->orderBy('incident_date', 'asc')->orderBy('id', 'asc')
            ->first();

        if ($next) {
            $calculator = app(\App\Services\Metrics\IncidentMetricsCalculator::class);
            $calculator->computeMtbf($next);
            $calculator->computeMttr($next);
            $calculator->computeCategoryMtbf($next);
            $calculator->computeMtbfAll($next);

            $next->saveQuietly();
        }

        Cache::increment('dashboard_cache_version');
        $this->clearAiContextCache();
    }
}
