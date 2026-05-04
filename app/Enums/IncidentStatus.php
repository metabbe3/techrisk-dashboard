<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum IncidentStatus: string
{
    use HasOptions;

    case Open = 'Open';
    case InProgress = 'In progress';
    case Finalization = 'Finalization';
    case Completed = 'Completed';

    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::InProgress => 'info',
            self::Finalization => 'primary',
            self::Completed => 'success',
        };
    }

    public static function fieldOrderExpression(string $column = 'incident_status'): string
    {
        $values = collect(self::cases())->map(fn (self $case) => "'{$case->value}'")->implode(', ');

        return "FIELD({$column}, {$values})";
    }
}
