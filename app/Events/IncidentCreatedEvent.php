<?php

declare(strict_types=1);
namespace App\Events;

use App\Models\Incident;

class IncidentCreatedEvent
{
    public function __construct(
        public Incident $incident,
    ) {}
}
