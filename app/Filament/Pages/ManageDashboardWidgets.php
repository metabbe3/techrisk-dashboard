<?php

namespace App\Filament\Pages;

use App\Models\UserDashboardPreference;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ManageDashboardWidgets extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationLabel = 'Customize Dashboard';

    protected static ?string $navigationGroup = 'Dashboard';

    protected static ?int $navigationSort = 1;

    public ?string $heading = 'Customize Your Dashboard';

    protected static string $view = 'filament.pages.manage-dashboard-widgets';

    public function mount(): void
    {
        $user = Auth::user();

        // Initialize default preferences if user has none
        $preferences = UserDashboardPreference::where('user_id', $user->id)->count();
        if ($preferences === 0) {
            UserDashboardPreference::initializeDefaultsForUser($user);
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                UserDashboardPreference::where('user_id', Auth::id())
                    ->orderBy('sort_order')
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Widget')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn ($record): string => $record->description ?? ''),
                ToggleColumn::make('is_enabled')
                    ->label('Enabled')
                    ->onColor('success')
                    ->offColor('gray')
                    ->sortable(),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->headerActions([
                Action::make('resetToDefaults')
                    ->label('Reset to Defaults')
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Reset Dashboard Widgets')
                    ->modalDescription('Are you sure you want to reset all dashboard widgets to their default settings? This will re-enable all widgets and reset their order.')
                    ->modalSubmitActionLabel('Yes, Reset')
                    ->action(function () {
                        $user = Auth::user();
                        UserDashboardPreference::resetForUser($user);

                        Notification::make()
                            ->success()
                            ->title('Dashboard widgets reset to defaults!')
                            ->send();
                    }),
            ])
            ->paginated(false);
    }
}
