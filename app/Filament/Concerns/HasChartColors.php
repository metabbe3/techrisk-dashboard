<?php

namespace App\Filament\Concerns;

trait HasChartColors
{
    protected static function chartColors(int $count): array
    {
        return array_slice([
            'rgba(99, 102, 241, 0.8)',
            'rgba(13, 148, 136, 0.8)',
            'rgba(245, 158, 11, 0.8)',
            'rgba(244, 63, 94, 0.8)',
            'rgba(16, 185, 129, 0.8)',
            'rgba(14, 165, 233, 0.8)',
            'rgba(139, 92, 246, 0.8)',
            'rgba(249, 115, 22, 0.8)',
            'rgba(6, 182, 212, 0.8)',
            'rgba(236, 72, 153, 0.8)',
            'rgba(132, 204, 22, 0.8)',
            'rgba(168, 85, 247, 0.8)',
        ], 0, $count);
    }

    protected static function chartBorderColors(int $count): array
    {
        return array_slice([
            'rgba(99, 102, 241, 1)',
            'rgba(13, 148, 136, 1)',
            'rgba(245, 158, 11, 1)',
            'rgba(244, 63, 94, 1)',
            'rgba(16, 185, 129, 1)',
            'rgba(14, 165, 233, 1)',
            'rgba(139, 92, 246, 1)',
            'rgba(249, 115, 22, 1)',
            'rgba(6, 182, 212, 1)',
            'rgba(236, 72, 153, 1)',
            'rgba(132, 204, 22, 1)',
            'rgba(168, 85, 247, 1)',
        ], 0, $count);
    }
}
