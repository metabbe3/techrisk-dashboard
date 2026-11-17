<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AiAgentEngine: string
{
    use HasOptions;

    case Php = 'php';
    case Go = 'go';
    case Static = 'static';

    public function color(): string
    {
        return match ($this) {
            self::Php => 'gray',
            self::Go => 'info',
            self::Static => 'warning',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Php => 'PHP — single dashboard call',
            self::Go => 'Go agent — tool loop',
            self::Static => 'Static — deliver text, no LLM',
        };
    }

    /** value => label, for Filament selects.
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->toArray();
    }
}
