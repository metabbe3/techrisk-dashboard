<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum FundStatus: string
{
    use HasOptions;

    case NonFundLoss = 'Non fundLoss';
    case ConfirmedLoss = 'Confirmed loss';
    case PotentialRecovery = 'Potential recovery';

    public function color(): string
    {
        return match ($this) {
            self::ConfirmedLoss => 'danger',
            self::NonFundLoss => 'success',
            self::PotentialRecovery => 'warning',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ConfirmedLoss => 'Fund Loss',
            self::NonFundLoss => 'Non Fund Loss',
            self::PotentialRecovery => 'Potential Recovery',
        };
    }

    public static function filterOptions(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->toArray();
    }
}
