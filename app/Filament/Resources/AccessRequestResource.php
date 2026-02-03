<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AccessRequestResource\Pages\ListAccessRequests;
use App\Filament\Resources\AccessRequestResource\Pages\ViewAccessRequest;
use App\Models\AccessRequest;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
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
        return false; // Use approve/reject actions instead
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->hasRole('admin');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Request Details')
                    ->schema([
                        Forms\Components\TextEntry::make('name')->label('Full Name'),
                        Forms\Components\TextEntry::make('email')->label('Email'),
                        Forms\Components\TextEntry::make('requested_duration_days')
                            ->label('Requested Duration')
                            ->formatStateUsing(fn ($state) => $state . ' days'),
                        Forms\Components\TextEntry::make('requested_years')
                            ->label('Requested Years')
                            ->badge()
                            ->formatStateUsing(fn ($state) => implode(', ', $state)),
                        Forms\Components\TextareaEntry::make('reason')
                            ->label('Reason')
                            ->rows(4),
                    ])->columns(2),

                Forms\Components\Section::make('Status Information')
                    ->schema([
                        Forms\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pending' => 'warning',
                                'approved' => 'success',
                                'rejected' => 'danger',
                                default => 'gray',
                            }),
                        Forms\Components\TextEntry::make('approvedBy.name')
                            ->label('Approved By')
                            ->visible(fn ($record) => $record->approved_by !== null),
                        Forms\Components\TextEntry::make('approved_at')
                            ->dateTime()
                            ->visible(fn ($record) => $record->approved_at !== null),
                        Forms\Components\TextareaEntry::make('rejection_reason')
                            ->label('Rejection Reason')
                            ->visible(fn ($record) => $record->rejection_reason !== null)
                            ->rows(2),
                    ])->columns(2),

                Forms\Components\Section::make('Actions')
                    ->schema([
                        Forms\Components\Placeholder::make('actions')
                            ->content(fn ($record) => view('filament.resources.access-request-actions', ['record' => $record])),
                    ])->visible(fn ($record) => $record->status === 'pending'),
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
                    ->formatStateUsing(fn ($state) => implode(', ', $state))
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
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['approvedBy', 'createdUser']);
    }
}
