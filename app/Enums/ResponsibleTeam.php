<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ResponsibleTeam: string
{
    use HasOptions;

    case Engineering = 'Engineering';
    case Operations = 'Operations';
    case Security = 'Security';
    case ITInfrastructure = 'IT Infrastructure';
    case Product = 'Product';
    case QA = 'QA';
    case ThirdParty = 'Third Party';
    case Management = 'Management';
}
