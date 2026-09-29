<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AiAgentMemoryKind: string
{
    use HasOptions;

    case Lesson = 'Lesson';
    case Outcome = 'Outcome';
    case Note = 'Note';
    case Feedback = 'Feedback';

    public function color(): string
    {
        return match ($this) {
            self::Lesson => 'info',
            self::Outcome => 'success',
            self::Note => 'gray',
            self::Feedback => 'warning',
        };
    }
}
