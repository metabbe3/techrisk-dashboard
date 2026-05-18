<?php

namespace App\Events;

use App\Models\Incident;

class IncidentEscalatedEvent
{
    public function __construct(
        public Incident $incident,
        public string $previousSeverity,
    ) {}
}
