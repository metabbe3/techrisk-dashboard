<?php

namespace App\Filament\Pages;

use App\Models\UserDashboardPreference;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ManageDashboardWidgets extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationLabel = 'Customize Dashboard';

    protected static ?string $navigationGroup = 'Dashboard';

    protected static ?int $navigationSort = 1;

    public ?string $heading = 'Customize Your Dashboard';

    public static function canAccess(): bool
    {
        return Auth::check();
    }

    protected static string $view = 'filament.pages.manage-dashboard-widgets';

    public ?array $availableWidgets = null;
    public ?array $enabledWidgets = null;

    public function mount(): void
    {
        $user = Auth::user();

        $this->availableWidgets = UserDashboardPreference::getAvailableWidgets();

        // Get user's current preferences
        $preferences = UserDashboardPreference::where('user_id', $user->id)
            ->orderBy('sort_order')
            ->get();

        // If user has no preferences, initialize defaults
        if ($preferences->isEmpty()) {
            UserDashboardPreference::initializeDefaultsForUser($user);
            $preferences = UserDashboardPreference::where('user_id', $user->id)
                ->orderBy('sort_order')
                ->get();
        }

        // Build enabled widgets array
        $this->enabledWidgets = [];
        foreach ($preferences as $pref) {
            $this->enabledWidgets[$pref->widget_class] = [
                'enabled' => $pref->is_enabled,
                'sort_order' => $pref->sort_order,
            ];
        }
    }

    public function toggleWidget(string $widgetClass): void
    {
        $user = Auth::user();
        $preference = UserDashboardPreference::where('user_id', $user->id)
            ->where('widget_class', $widgetClass)
            ->first();

        if ($preference) {
            $preference->update(['is_enabled' => !$preference->is_enabled]);
            $this->enabledWidgets[$widgetClass]['enabled'] = !$preference->is_enabled;
        }

        $this->js('window.location.reload()');
    }

    public function saveOrder(array $widgetClasses): void
    {
        $user = Auth::user();

        foreach ($widgetClasses as $index => $widgetClass) {
            UserDashboardPreference::where('user_id', $user->id)
                ->where('widget_class', $widgetClass)
                ->update(['sort_order' => $index]);
        }

        $this->notify('success', 'Widget order saved!');
    }

    public function resetToDefaults(): void
    {
        $user = Auth::user();
        UserDashboardPreference::resetForUser($user);

        $this->notify('success', 'Dashboard widgets reset to defaults!');
        $this->js('window.location.reload()');
    }

    private function notify(string $type, string $message): void
    {
        $this->dispatchBrowserEvent('notify', [
            'type' => $type,
            'message' => $message,
        ]);
    }
}
