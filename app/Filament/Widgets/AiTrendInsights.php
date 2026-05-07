<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class AiTrendInsights extends Widget
{
    protected static string $view = 'filament.widgets.ai-trend-insights';

    protected static ?int $sort = 100;

    protected int | string | array $columnSpan = 'full';

    public ?string $start_date = null;

    public ?string $end_date = null;
}
