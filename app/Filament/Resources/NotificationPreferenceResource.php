<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationPreferenceResource\Pages;
use App\Models\NotificationPreference;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

class NotificationPreferenceResource extends Resource
{
    protected static ?string $model = NotificationPreference::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Notification Settings';

    protected static ?string $navigationGroup = 'Notifications';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Email Notifications')
                    ->description('Choose which email notifications you want to receive')
                    ->schema([
                        Toggle::make('email_incident_assignment')
                            ->label('Incident Assignment')
                            ->helperText('When you are assigned as PIC for an incident')
                            ->default(true),
                        Toggle::make('email_incident_update')
                            ->label('Incident Updates')
                            ->helperText('When an incident you are assigned to is updated')
                            ->default(true),
                        Toggle::make('email_incident_status_changed')
                            ->label('Status Changes')
                            ->helperText('When incident status changes')
                            ->default(true),
                        Toggle::make('email_status_update')
                            ->label('New Status Updates')
                            ->helperText('When new status updates are added')
                            ->default(true),
                        Toggle::make('email_action_improvement_reminder')
                            ->label('Action Improvement Reminders')
                            ->helperText('When action improvements are due soon')
                            ->default(true),
                        Toggle::make('email_action_improvement_overdue')
                            ->label('Overdue Action Improvements')
                            ->helperText('When action improvements are overdue')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('In-App Notifications')
                    ->description('Choose which in-app notifications you want to receive')
                    ->schema([
                        Toggle::make('database_incident_assignment')
                            ->label('Incident Assignment')
                            ->helperText('Bell icon notifications for incident assignment')
                            ->default(true),
                        Toggle::make('database_incident_update')
                            ->label('Incident Updates')
                            ->helperText('Bell icon notifications for incident updates')
                            ->default(true),
                        Toggle::make('database_incident_status_changed')
                            ->label('Status Changes')
                            ->helperText('Bell icon notifications for status changes')
                            ->default(true),
                        Toggle::make('database_status_update')
                            ->label('New Status Updates')
                            ->helperText('Bell icon notifications for new status updates')
                            ->default(true),
                        Toggle::make('database_action_improvement_reminder')
                            ->label('Action Improvement Reminders')
                            ->helperText('Bell icon notifications for upcoming due dates')
                            ->default(true),
                        Toggle::make('database_action_improvement_overdue')
                            ->label('Overdue Action Improvements')
                            ->helperText('Bell icon notifications for overdue items')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email_enabled_count')
                    ->label('Email Types Enabled')
                    ->sortable()
                    ->badge()
                    ->color('success'),
                TextColumn::make('database_enabled_count')
                    ->label('In-App Types Enabled')
                    ->sortable()
                    ->badge()
                    ->color('primary'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationPreferences::route('/'),
            'edit' => Pages\EditNotificationPreference::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }
}
