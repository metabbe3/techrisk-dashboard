<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ApiEndpoint;
use App\Filament\Resources\ApiTokenResource\Pages;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ApiTokenResource extends Resource
{
    protected static ?string $model = PersonalAccessToken::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'User Management';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return Auth::user()?->can('manage api tokens') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Token Information')
                    ->description('Create a new API token or edit token settings')
                    ->schema([
                        Forms\Components\Placeholder::make('service_account_hint')
                            ->content(new \Illuminate\Support\HtmlString(
                                'No service account yet? <a href="'.ServiceAccountResource::getUrl('create').'" class="fi-link text-primary-600 font-semibold underline">Create one first</a>'
                            ))
                            ->visible(fn () => User::query()->serviceAccounts()->doesntExist()),

                        Forms\Components\Select::make('tokenable_id')
                            ->label('Account')
                            ->options(function () {
                                $serviceAccounts = User::query()
                                    ->serviceAccounts()
                                    ->get()
                                    ->mapWithKeys(fn ($user) => [
                                        $user->id => '[Service] '.$user->name.' ('.$user->email.')',
                                    ]);

                                $humanUsers = User::query()
                                    ->humanUsers()
                                    ->with('roles')
                                    ->get()
                                    ->filter(fn ($user) => $user->can('access api'))
                                    ->mapWithKeys(fn ($user) => [
                                        $user->id => $user->name.' ('.$user->email.')',
                                    ]);

                                return [
                                    'Service Accounts' => $serviceAccounts->toArray(),
                                    'Users' => $humanUsers->toArray(),
                                ];
                            })
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->dehydrated()
                            ->helperText('Prefer service accounts for API integrations'),

                        Forms\Components\TextInput::make('name')
                            ->label('Token Name')
                            ->helperText('A descriptive name for this token (e.g., "Production Integration", "Dev Script")')
                            ->required()
                            ->maxLength(255)
                            ->default(fn () => 'API Token - '.now()->format('Y-m-d')),

                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label('Expiration Date')
                            ->required()
                            ->default(now()->addMonths(6))
                            ->helperText('Token will expire on this date. Auto-renewal will extend it if configured.'),

                        Forms\Components\Select::make('renewal_minutes')
                            ->label('Auto-Renewal Period')
                            ->options([
                                null => 'None (no auto-renewal)',
                                43200 => '30 days per use',
                                86400 => '60 days per use',
                                129600 => '90 days per use',
                            ])
                            ->default(43200)
                            ->helperText('Each API call extends the token expiration by this amount'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Endpoint Access & Abilities')
                    ->description('Control which API endpoints and data this token can access')
                    ->schema([
                        Forms\Components\CheckboxList::make('abilities')
                            ->label('Token Abilities')
                            ->options([
                                '*' => 'Full Access',
                                'read:pii' => 'Read PII (email addresses)',
                            ])
                            ->default(['*'])
                            ->descriptions([
                                '*' => 'Grants all abilities including PII access',
                                'read:pii' => 'Include email addresses and other PII in API responses',
                            ])
                            ->columns(2),

                        Forms\Components\CheckboxList::make('allowed_endpoints')
                            ->label('Allowed Endpoints')
                            ->helperText('If no endpoints are selected, the token will have access to all endpoints')
                            ->options(function () {
                                return collect(ApiEndpoint::cases())
                                    ->mapWithKeys(fn ($endpoint) => [
                                        $endpoint->value => $endpoint->label(),
                                    ]);
                            })
                            ->descriptions(function () {
                                return collect(ApiEndpoint::cases())
                                    ->mapWithKeys(fn ($endpoint) => [
                                        $endpoint->value => 'Route: '.$endpoint->routePattern(),
                                    ]);
                            })
                            ->bulkToggleable()
                            ->searchable()
                            ->columns(1),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->where('tokenable_type', User::class))
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record): string => $record->tokenable?->name ?? 'Unknown User'),

                Tables\Columns\TextColumn::make('tokenable.email')
                    ->label('Account')
                    ->sortable()
                    ->formatStateUsing(function ($record): string {
                        $user = $record->tokenable;

                        return $user?->is_service_account ? '[SVC] '.$user->email : $user->email;
                    }),

                Tables\Columns\IconColumn::make('status')
                    ->label('Status')
                    ->icon(fn ($record): string => match (true) {
                        $record->isDisabled() => 'heroicon-o-no-symbol',
                        $record->isExpired() => 'heroicon-o-clock',
                        default => 'heroicon-o-check-circle',
                    })
                    ->color(fn ($record): string => match (true) {
                        $record->isDisabled() => 'danger',
                        $record->isExpired() => 'warning',
                        default => 'success',
                    })
                    ->tooltip(fn ($record): string => match (true) {
                        $record->isDisabled() => 'Disabled',
                        $record->isExpired() => 'Expired',
                        default => 'Active',
                    }),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->color(fn ($state): string => $state && $state->isPast() ? 'danger' : ($state && $state->diffInDays(now()) < 30 ? 'warning' : 'success'))
                    ->description(fn ($state): ?string => $state ? $state->diffForHumans() : null),

                Tables\Columns\TextColumn::make('last_used_at')
                    ->label('Last Used')
                    ->dateTime('M d, Y H:i')
                    ->placeholder('Never')
                    ->sortable()
                    ->description(fn ($state): ?string => filled($state) ? now()->diffInDays($state).' days ago' : null),

                Tables\Columns\TextColumn::make('allowed_endpoints')
                    ->label('Endpoint Access')
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(function ($state): string {
                        if (is_string($state)) {
                            $state = json_decode($state, true) ?? [];
                        }
                        if (empty($state)) {
                            return 'All Endpoints';
                        }

                        $count = count($state);

                        return $count.' endpoint'.($count > 1 ? 's' : '');
                    })
                    ->tooltip(function ($record): ?string {
                        $endpoints = $record->allowed_endpoints;
                        if (is_string($endpoints)) {
                            $endpoints = json_decode($endpoints, true) ?? [];
                        }
                        if (empty($endpoints)) {
                            return 'Unrestricted access to all endpoints';
                        }

                        return collect($endpoints)
                            ->map(fn ($endpoint) => ApiEndpoint::tryFrom($endpoint)?->label() ?? $endpoint)
                            ->join(', ');
                    }),

                Tables\Columns\IconColumn::make('has_pii')
                    ->label('PII')
                    ->boolean()
                    ->state(function ($record): bool {
                        $abilities = $record->abilities ?? [];
                        if (is_string($abilities)) {
                            $abilities = json_decode($abilities, true) ?? [];
                        }

                        return in_array('*', $abilities) || in_array('read:pii', $abilities);
                    })
                    ->trueIcon('heroicon-o-eye')
                    ->falseIcon('heroicon-o-eye-slash')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(function ($record): string {
                        $abilities = $record->abilities ?? [];
                        if (is_string($abilities)) {
                            $abilities = json_decode($abilities, true) ?? [];
                        }

                        return in_array('*', $abilities) || in_array('read:pii', $abilities)
                            ? 'Can read PII (email addresses)'
                            : 'No PII access';
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user')
                    ->query(function ($query, $data) {
                        if (isset($data['value'])) {
                            $query->where('tokenable_id', $data['value']);
                        }
                    })
                    ->options(function () {
                        return User::query()
                            ->whereHas('tokens')
                            ->get()
                            ->mapWithKeys(fn ($user) => [$user->id => $user->name.' ('.$user->email.')']);
                    })
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('expiring_soon')
                    ->label('Expiring Soon (30 days)')
                    ->query(fn ($query) => $query->where('expires_at', '<', now()->addDays(30))->whereNull('disabled_at')),

                Tables\Filters\Filter::make('disabled')
                    ->label('Disabled Tokens')
                    ->query(fn ($query) => $query->whereNotNull('disabled_at')),

                Tables\Filters\Filter::make('expired')
                    ->label('Expired Tokens')
                    ->query(fn ($query) => $query->where('expires_at', '<', now())->whereNull('disabled_at')),

                Tables\Filters\Filter::make('never_used')
                    ->label('Never Used')
                    ->query(fn ($query) => $query->whereNull('last_used_at')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('re-enable')
                    ->label('Re-enable')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn ($record): bool => $record->isDisabled())
                    ->requiresConfirmation()
                    ->modalHeading('Re-enable API Token')
                    ->modalDescription('This will re-enable the token and optionally extend its expiration.')
                    ->action(function ($record) {
                        $record->forceFill([
                            'disabled_at' => null,
                            'expires_at' => now()->addMonths(6),
                        ])->save();
                    }),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalHeading('Revoke API Token')
                    ->modalDescription('Are you sure you want to revoke this token? This action cannot be undone.')
                    ->modalSubmitActionLabel('Revoke Token'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Revoke Selected Tokens')
                        ->requiresConfirmation(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListApiTokens::route('/'),
            'create' => Pages\CreateApiToken::route('/create'),
            'view' => Pages\ViewApiToken::route('/{record}'),
            'edit' => Pages\EditApiToken::route('/{record}/edit'),
        ];
    }
}
