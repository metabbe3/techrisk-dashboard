<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum IncidentClassification: string
{
    use HasOptions;

    case Incident = 'Incident';
    case Issue = 'Issue';
}
