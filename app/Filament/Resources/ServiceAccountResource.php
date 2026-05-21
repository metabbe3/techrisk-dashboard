<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceAccountResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ServiceAccountResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-server';

    protected static ?string $navigationGroup = 'User Management';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Service Accounts';

    protected static ?string $modelLabel = 'Service Account';

    protected static ?string $pluralModelLabel = 'Service Accounts';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage service accounts') ?? false;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->serviceAccounts();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Service Account Details')
                    ->description('Create a service account for API integrations. Service accounts cannot log in to the dashboard.')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('E.g., "Data Pipeline", "Monitoring System"'),

                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->suffixAction(
                                Forms\Components\Actions\Action::make('generate_email')
                                    ->icon('heroicon-o-sparkles')
                                    ->action(function (Forms\Set $set, Forms\Get $get) {
                                        $name = Str::slug($get('name') ?? 'service', '-');
                                        $set('email', "svc-{$name}@service.internal");
                                    })
                                    ->tooltip('Auto-generate service email')
                            ),

                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create')
                            ->helperText('Used internally. Service accounts cannot login to the dashboard.'),

                        Forms\Components\Hidden::make('is_service_account')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('tokens_count')
                    ->label('Active Tokens')
                    ->counts('tokens')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('This will also revoke all tokens associated with this service account.'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiceAccounts::route('/'),
            'create' => Pages\CreateServiceAccount::route('/create'),
            'edit' => Pages\EditServiceAccount::route('/{record}/edit'),
        ];
    }
}
