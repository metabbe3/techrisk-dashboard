<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'User Management';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255),
                Forms\Components\DateTimePicker::make('email_verified_at')
                    ->disabled()
                    ->dehydrated(false),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create'),
                Select::make('roles')
                    ->multiple()
                    ->relationship('roles', 'name')
                    ->preload(),
                Forms\Components\Section::make('Access Settings')
                    ->description('Configure user access and expiry')
                    ->schema([
                        Forms\Components\DateTimePicker::make('access_expiry')
                            ->label('Access Expires At')
                            ->helperText('If set, user will not be able to access the dashboard after this date')
                            ->seconds(false)
                            ->native(false)
                            ->displayFormat('M d, Y H:i')
                            ->minDate(now()->subMonth())
                            ->hint(function ($state) {
                                if (! $state) {
                                    return 'No expiry set - user has permanent access';
                                }

                                $expiry = \Carbon\Carbon::parse($state);
                                if ($expiry->isPast()) {
                                    return 'Access has expired';
                                }

                                $daysLeft = now()->diffInDays($expiry);
                                if ($daysLeft <= 7) {
                                    return "Expires in {$daysLeft} day(s) - almost expired!";
                                }

                                return "Expires in {$daysLeft} day(s)";
                            })
                            ->hintColor(fn ($state) => !$state ? 'success' : (\Carbon\Carbon::parse($state)->isPast() ? 'danger' : ((\Carbon\Carbon::parse($state)->diffInDays(now()) <= 7) ? 'warning' : 'info')))
                            ->required(false),
                    ])
                    ->compact(),
                Forms\Components\Section::make('Data Access Control')
                    ->description('Configure which years of data (Incidents & Audit Logs) this user can access')
                    ->schema([
                        Forms\Components\Checkbox::make('audit_log_settings.can_view_all_logs')
                            ->label('Full Access (All Years)')
                            ->helperText('If enabled, user can see ALL data regardless of year. Admins have this by default.')
                            ->dehydrated(false)
                            ->afterStateHydrated(function ($component, $state, $record) {
                                if ($record && $record->auditLogSettings) {
                                    $component->state($record->auditLogSettings->can_view_all_logs);
                                }
                            })
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $set('audit_log_settings.allowed_years', []);
                                }
                            }),
                        Forms\Components\TagsInput::make('audit_log_settings.allowed_years')
                            ->label('Allowed Data Years')
                            ->helperText('User will only see incidents and audit logs from these years')
                            ->placeholder('Add year (e.g., 2025, 2026)')
                            ->dehydrated(false)
                            ->afterStateHydrated(function ($component, $state, $record) {
                                if ($record && $record->auditLogSettings) {
                                    $component->state($record->auditLogSettings->allowed_years ?? []);
                                }
                            })
                            ->visible(fn (callable $get) => ! $get('audit_log_settings.can_view_all_logs'))
                            ->required(fn (callable $get) => ! $get('audit_log_settings.can_view_all_logs')),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('access_expiry')
                    ->label('Access Expiry')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->toggleable()
                    ->badge()
                    ->color(fn ($state) => !$state ? 'success' : (\Carbon\Carbon::parse($state)->isPast() ? 'danger' : 'gray'))
                    ->formatStateUsing(function ($state) {
                        if (!$state) {
                            return 'Never';
                        }

                        $expiry = \Carbon\Carbon::parse($state);
                        if ($expiry->isPast()) {
                            return 'Expired';
                        }

                        $daysLeft = now()->diffInDays($expiry);
                        if ($daysLeft <= 7) {
                            return "In {$daysLeft}d";
                        }

                        return $expiry->format('M d, Y');
                    }),
                Tables\Columns\TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->databaseTransaction(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->databaseTransaction(),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->can('view users');
    }
}
