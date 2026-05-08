<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class AiChat extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'TechRisk AI';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.ai-chat';

    protected static bool $isDiscovered = true;

    public static function canAccess(): bool
    {
        return Auth::check() && Auth::user()->can('access ai chat');
    }

    public function getViewData(): array
    {
        return [
            'models' => app(\App\Services\Ai\AiTextService::class)->getAvailableModels(),
            'defaultModel' => \App\Models\AiSetting::get('default_model', config('ai.default_model', 'SMART-MODEL')),
        ];
    }
}
