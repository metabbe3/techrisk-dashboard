<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AiAgentRunStatus: string
{
    use HasOptions;

    case Pending = 'Pending';
    case Running = 'Running';
    case Completed = 'Completed';
    case Failed = 'Failed';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Running => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
