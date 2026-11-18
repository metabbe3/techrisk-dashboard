<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AiAgentFrequency: string
{
    use HasOptions;

    case Manual = 'Manual';
    case Cron = 'Cron';
    case Once = 'Once';

    public function color(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::Cron => 'info',
            self::Once => 'success',
        };
    }
}
