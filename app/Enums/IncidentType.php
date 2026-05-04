<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum IncidentType: string
{
    use HasOptions;

    case Tech = 'Tech';
    case NonTech = 'Non-tech';
    case CompanyLoss = 'Company Loss';
}
