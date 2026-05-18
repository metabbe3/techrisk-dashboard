<?php

namespace App\Events;

use App\Models\Incident;

class IncidentCreatedEvent
{
    public function __construct(
        public Incident $incident,
    ) {}
}
