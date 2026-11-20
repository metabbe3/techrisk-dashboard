<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class AiChat extends Page
{
    protected static ?string $navigationIcon = null; // icon dropped: member renders as a cluster tab

    protected static ?string $navigationLabel = 'TechRisk AI';

    protected static ?string $title = 'TechRisk AI';

    protected static ?string $cluster = \App\Filament\Clusters\AiCluster::class;

    protected static \Filament\Pages\SubNavigationPosition $subNavigationPosition = \Filament\Pages\SubNavigationPosition::Top;

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.ai-chat';

    protected static bool $isDiscovered = true;

    public static function canAccess(): bool
    {
        return Auth::check() && Auth::user()->can('access ai chat');
    }

    public function getViewData(): array
    {
        return [
            'models' => app(\App\Services\Ai\AiTextService::class)->getModelsForPicker(),
            'defaultModel' => \App\Models\AiSetting::get('default_model', config('ai.default_model', 'SMART-MODEL')),
        ];
    }
}
