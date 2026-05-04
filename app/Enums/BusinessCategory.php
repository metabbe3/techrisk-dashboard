<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum BusinessCategory: string
{
    use HasOptions;

    case Fraud = 'Fraud';
    case Operational = 'Operational';
    case ITSecurity = 'IT Security';
    case Compliance = 'Compliance';
    case SystemFailure = 'System Failure';
    case HumanError = 'Human Error';
    case ExternalAttack = 'External Attack';
    case ProcessGap = 'Process Gap';
}
