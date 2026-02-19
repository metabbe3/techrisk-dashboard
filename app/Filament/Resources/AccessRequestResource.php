<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AccessRequestResource\Pages\EditAccessRequest;
use App\Filament\Resources\AccessRequestResource\Pages\ListAccessRequests;
use App\Filament\Resources\AccessRequestResource\Pages\ViewAccessRequest;
use App\Models\AccessRequest;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AccessRequestResource extends Resource
{
    protected static ?string $model = AccessRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';

    protected static ?string $navigationGroup = 'User Management';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return auth()->user()->can('manage users');
    }

    public static function canCreate(): bool
    {
        return false; // Requests are created via public form
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->hasRole('admin');
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->hasRole('admin');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label('Full Name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255),

                Select::make('requested_duration_days')
                    ->label('Access Duration')
                    ->required()
                    ->options([
                        7 => '7 days',
                        14 => '14 days',
                        30 => '30 days',
                        60 => '60 days',
                        90 => '90 days',
                        180 => '180 days',
                        365 => '365 days',
                    ]),

                CheckboxList::make('requested_years')
                    ->label('Years to Access')
                    ->required()
                    ->options(function () {
                        $years = [];
                        $currentYear = (int) date('Y');
                        for ($i = $currentYear - 2; $i <= $currentYear + 1; $i++) {
                            $years[$i] = (string) $i;
                        }
                        return $years;
                    }),

                Textarea::make('reason')
                    ->label('Reason for Access Request')
                    ->required()
                    ->rows(3),

                Select::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-envelope'),

                TextColumn::make('requested_duration_days')
                    ->label('Duration')
                    ->formatStateUsing(fn ($state) => $state . 'd')
                    ->sortable(),

                TextColumn::make('requested_years')
                    ->label('Years')
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : $state)
                    ->searchable(),

                TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(30)
                    ->tooltip(fn (AccessRequest $record): string => $record->reason),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->description(fn (AccessRequest $record): string => $record->created_at->diffForHumans()),

                TextColumn::make('approvedBy.name')
                    ->label('Reviewed By')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\DatePicker::make('custom_expiry')
                            ->label('Custom Access Expiry (Optional)')
                            ->helperText('Leave blank to use requested duration')
                            ->minDate(now()->addDay())
                            ->native(false),
                        Forms\Components\Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2)
                            ->helperText('Optional notes for this approval'),
                    ])
                    ->action(function (AccessRequest $record, array $data) {
                        $expiry = $data['custom_expiry'] ?? null;
                        $record->approve(auth()->id(), $expiry);
                    })
                    ->visible(fn (AccessRequest $record) => $record->status === 'pending'),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('Rejection Reason')
                            ->required()
                            ->rows(3)
                            ->placeholder('Please explain why this request is being rejected...'),
                    ])
                    ->action(function (AccessRequest $record, array $data) {
                        $record->reject(auth()->id(), $data['rejection_reason']);
                    })
                    ->visible(fn (AccessRequest $record) => $record->status === 'pending'),
            ])
            ->bulkActions([
                //
            ])
            ->emptyStateHeading('No access requests found')
            ->emptyStateDescription('Access requests submitted by users will appear here.')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccessRequests::route('/'),
            'view' => ViewAccessRequest::route('/{record}'),
            'edit' => EditAccessRequest::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['approvedBy', 'createdUser']);
    }
}
