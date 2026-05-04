<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum RootCauseCategory: string
{
    use HasOptions;

    case HumanError = 'Human Error';
    case SystemBug = 'System Bug';
    case ProcessFailure = 'Process Failure';
    case ThirdParty = 'Third Party';
    case ConfigurationError = 'Configuration Error';
    case SecurityBreach = 'Security Breach';
    case Infrastructure = 'Infrastructure';
    case DesignFlaw = 'Design Flaw';
}
