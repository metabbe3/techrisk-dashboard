<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\TokensRelationManager;
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

    protected static ?string $navigationIcon = null; // icon dropped: member renders as a cluster tab

    protected static ?string $cluster = \App\Filament\Clusters\UserManagementCluster::class;

    protected static \Filament\Pages\SubNavigationPosition $subNavigationPosition = \Filament\Pages\SubNavigationPosition::Top;

    protected static ?int $navigationSort = 1;

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
                Select::make('manager_id')
                    ->label('Manager')
                    ->relationship('manager', 'name', modifyQueryUsing: fn ($query) => $query->humanUsers()->orderBy('name'))
                    ->searchable()
                    // No preload — 2.5k+ humans; searchable() alone does server-side search.
                    ->helperText('Org tree — overdue action-improvement escalations climb this chain'),
                Forms\Components\TextInput::make('job_title')
                    ->label('Job title')
                    ->maxLength(255)
                    ->helperText('Filled by the Entra directory sync'),
                Forms\Components\TextInput::make('department')
                    ->label('Team / department')
                    ->maxLength(255)
                    ->helperText('Filled by the Entra directory sync'),
                Select::make('teams')
                    ->label('Responsible teams')
                    ->relationship('teams', 'name', fn ($query) => $query->where('type', \App\Models\Category::TYPE_RESPONSIBLE_TEAM))
                    ->multiple()
                    ->preload()
                    ->searchable()
                    // Editing by hand flags the mapping manual — users:sync-teams
                    // skips flagged users (clear the checkbox to re-sync).
                    ->live()
                    ->afterStateUpdated(fn (callable $set) => $set('teams_manual', true))
                    ->helperText('Auto-mapped from Department by users:sync-teams. Feeds the team-viewer incident slice.'),
                Forms\Components\Checkbox::make('teams_manual')
                    ->label('Manual team mapping (skip auto-sync)'),
                Forms\Components\Section::make('Access Settings')
                    ->description('Configure user access and expiry')
                    ->schema([
                        Forms\Components\Toggle::make('can_login')
                            ->label('Can log in to the dashboard')
                            ->helperText('Directory-only contacts (auto-created from action-improvement PIC emails) are off — flip this to grant access. API tokens are unaffected.')
                            ->default(true),
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
                            ->hintColor(fn ($state) => ! $state ? 'success' : (\Carbon\Carbon::parse($state)->isPast() ? 'danger' : ((\Carbon\Carbon::parse($state)->diffInDays(now()) <= 7) ? 'warning' : 'info')))
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
                            ->live()
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
            ->modifyQueryUsing(fn ($query) => $query->with(['roles', 'manager', 'teams']))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\IconColumn::make('can_login')
                    ->label('Login')
                    ->boolean()
                    ->tooltip(fn ($record) => $record->can_login ? null : 'Directory-only contact — flip Can log in to grant access')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('manager.name')
                    ->label('Manager')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('job_title')
                    ->label('Title')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('department')
                    ->label('Team')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('teams.name')
                    ->label('Responsible teams')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('access_expiry')
                    ->label('Access Expiry')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->toggleable()
                    ->badge()
                    ->color(fn ($state) => ! $state ? 'success' : (\Carbon\Carbon::parse($state)->isPast() ? 'danger' : 'gray'))
                    ->formatStateUsing(function ($state) {
                        if (! $state) {
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
                Tables\Actions\Action::make('orgChart')
                    ->label('Org chart')
                    ->icon('heroicon-o-squares-2x2')
                    ->color('gray')
                    ->modalHeading(fn (User $record) => 'Org chart — '.$record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (User $record) => view('filament.org-chart', [
                        'chain' => static::orgChain($record),
                        'teammates' => $record->manager_id === null
                            ? collect()
                            : User::query()
                                ->where('manager_id', $record->manager_id)
                                ->whereKeyNot($record->getKey())
                                ->humanUsers()
                                ->orderBy('name')
                                ->get(['id', 'name', 'email', 'job_title']),
                    ])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->databaseTransaction(),
                ]),
            ]);
    }

    /**
     * Up the manager chain, nearest lead first, max 3 levels. Loop-guarded
     * (same guard as User::ancestorAt).
     *
     * @return list<User>
     */
    public static function orgChain(User $user): array
    {
        $chain = [];
        $seen = [$user->id];

        for ($i = 0; $i < 3; $i++) {
            $manager = $user->manager()->first();

            if ($manager === null || in_array($manager->id, $seen)) {
                break;
            }

            $chain[] = $manager;
            $seen[] = $manager->id;
            $user = $manager;
        }

        return $chain;
    }

    public static function getRelations(): array
    {
        return [
            TokensRelationManager::class,
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
}
