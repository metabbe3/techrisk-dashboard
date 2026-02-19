<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ApiEndpoint;
use App\Filament\Resources\ApiTokenResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

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
                    ->description('Create a new API token for a user')
                    ->schema([
                        Forms\Components\Select::make('tokenable_id')
                            ->label('User')
                            ->options(function () {
                                return User::query()
                                    ->with('roles')
                                    ->get()
                                    ->filter(fn ($user) => $user->can('access api'))
                                    ->mapWithKeys(fn ($user) => [
                                        $user->id => $user->name.' ('.$user->email.')',
                                    ]);
                            })
                            ->searchable()
                            ->required()
                            ->live(),

                        Forms\Components\TextInput::make('name')
                            ->label('Token Name')
                            ->helperText('A descriptive name for this token (e.g., "Production Integration", "Dev Script")')
                            ->required()
                            ->maxLength(255)
                            ->default(fn () => 'API Token - '.now()->format('Y-m-d')),

                        Forms\Components\KeyValue::make('abilities')
                            ->label('Token Abilities')
                            ->helperText('Optional: Define specific abilities for this token')
                            ->keyLabel('Ability')
                            ->valueLabel('Value')
                            ->default(['*']),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Endpoint Access')
                    ->description('Select which API endpoints this token can access')
                    ->schema([
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
                    ->description(fn ($record): string => $record->tokenable?->name ?? 'Unknown User'
                    ),

                Tables\Columns\TextColumn::make('tokenable.email')
                    ->label('User Email')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_used_at')
                    ->label('Last Used')
                    ->dateTime('M d, Y H:i')
                    ->sortable()
                    ->default('Never')
                    ->color(fn ($state): string => ! $state ? 'gray' : (now()->diffInDays($state) > 25 ? 'danger' : 'success')
                    )
                    ->description(fn ($state): ?string => $state ? now()->diffInDays($state).' days ago' : null
                    ),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('allowed_endpoints')
                    ->label('Endpoint Access')
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(function ($state): string {
                        if (empty($state)) {
                            return 'All Endpoints';
                        }

                        $count = count($state);

                        return $count.' endpoint'.($count > 1 ? 's' : '');
                    })
                    ->tooltip(function ($record): ?string {
                        if (empty($record->allowed_endpoints)) {
                            return 'Unrestricted access to all endpoints';
                        }

                        return collect($record->allowed_endpoints)
                            ->map(fn ($endpoint) => ApiEndpoint::tryFrom($endpoint)?->label() ?? $endpoint)
                            ->join(', ');
                    }),

                Tables\Columns\IconColumn::make('abilities')
                    ->label('Wildcard')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->default(true)
                    ->tooltip(fn ($record): string => in_array('*', $record->abilities ?? [])
                            ? 'Full access token'
                            : 'Restricted abilities'
                    ),
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
                    ->label('Expiring Soon (25+ days inactive)')
                    ->query(fn ($query) => $query->where('last_used_at', '<', now()->subDays(25)))
                    ->indicateUsing(function (array $data): ?string {
                        if (isset($data['expiring_soon'])) {
                            return 'Tokens expiring soon (25+ days inactive)';
                        }

                        return null;
                    }),

                Tables\Filters\Filter::make('never_used')
                    ->label('Never Used')
                    ->query(fn ($query) => $query->whereNull('last_used_at')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
        ];
    }
}
