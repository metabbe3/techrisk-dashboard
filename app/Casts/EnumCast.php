<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use UnitEnum;

/**
 * Casts a column to a backed enum, tolerating legacy mixed-case data
 * (e.g. lowercase 'p1' stored by old factories/seeders → Severity::P1).
 * Exact match is tried first; a case-insensitive fallback follows.
 */
class EnumCast implements CastsAttributes
{
    public function __construct(
        private string $enumClass,
    ) {}

    public function get($model, string $key, mixed $value, array $attributes)
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        if ($enum = ($this->enumClass)::tryFrom($value)) {
            return $enum;
        }

        foreach (($this->enumClass)::cases() as $case) {
            if (strcasecmp($case->value, $value) === 0) {
                return $case;
            }
        }

        return null;
    }

    public function set($model, string $key, mixed $value, array $attributes)
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof UnitEnum ? $value->value : $value;
    }
}
