<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

trait HasOptions
{
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (\BackedEnum $case) => [$case->value => $case->value])->toArray();
    }
}
