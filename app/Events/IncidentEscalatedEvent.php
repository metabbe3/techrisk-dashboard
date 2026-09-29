<?php

declare(strict_types=1);
namespace App\Events;

use App\Models\Incident;

class IncidentEscalatedEvent
{
    public function __construct(
        public Incident $incident,
        public string $previousSeverity,
    ) {}
}
