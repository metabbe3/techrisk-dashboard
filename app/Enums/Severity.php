<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Severity: string
{
    use HasOptions;

    case P1 = 'P1';
    case P2 = 'P2';
    case P3 = 'P3';
    case P4 = 'P4';
    case G = 'G';
    case X1 = 'X1';
    case X2 = 'X2';
    case X3 = 'X3';
    case X4 = 'X4';
    case NonIncident = 'Non Incident';

    public const METRIC_ELIGIBLE = ['P1', 'P2', 'P3', 'P4', 'X1', 'X2', 'X3', 'X4'];

    public function color(): string
    {
        return match ($this) {
            self::P1 => 'danger',
            self::P2 => 'warning',
            self::P3 => 'info',
            self::P4, self::G => 'success',
            default => 'gray',
        };
    }

    public static function fieldOrderExpression(string $column = 'severity'): string
    {
        $values = collect(self::cases())->map(fn (self $case) => "'{$case->value}'")->implode(', ');

        return "FIELD({$column}, {$values})";
    }
}
