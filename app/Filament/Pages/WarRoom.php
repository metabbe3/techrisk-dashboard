<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class WarRoom extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'AI Retrospective';

    protected static ?string $title = 'AI Retrospective';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.war-room';

    protected static bool $isDiscovered = true;

    public static function canAccess(): bool
    {
        return Auth::check() && Auth::user()->can('access war room');
    }

    public function getViewData(): array
    {
        return [
            'models' => app(\App\Services\Ai\AiTextService::class)->getModelsForPicker(),
            'defaultModel' => \App\Models\AiSetting::get('default_model', config('ai.default_model', 'SMART-MODEL')),
        ];
    }
}
