<?php

namespace App\Listeners\Ai;

use App\Events\IncidentCreatedEvent;
use App\Events\IncidentEscalatedEvent;
use App\Jobs\Ai\ProactiveIncidentAnalysisJob;

class AnalyzeNewIncident
{
    public function handle(IncidentCreatedEvent|IncidentEscalatedEvent $event): void
    {
        if (! config('ai.perception.enabled', true)) {
            return;
        }

        $type = $event instanceof IncidentEscalatedEvent ? 'escalation' : 'new_incident';

        ProactiveIncidentAnalysisJob::dispatch(
            $event->incident->id,
            $type,
        );
    }
}
