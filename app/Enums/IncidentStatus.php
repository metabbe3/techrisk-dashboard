<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Illuminate\Support\Facades\DB;

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

    /**
     * Portable FIELD()-equivalent ordering: CASE WHEN on SQLite (FIELD is
     * MySQL-only), native FIELD() elsewhere. Used by orderByRaw() call sites.
     */
    public static function fieldOrderExpression(string $column = 'incident_status'): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $whens = collect(self::cases())
                ->map(fn (self $case, int $i) => "WHEN {$column} = '{$case->value}' THEN {$i}")
                ->implode(' ');

            return "CASE {$whens} ELSE ".count(self::cases()).' END';
        }

        $values = collect(self::cases())->map(fn (self $case) => "'{$case->value}'")->implode(', ');

        return "FIELD({$column}, {$values})";
    }
}
