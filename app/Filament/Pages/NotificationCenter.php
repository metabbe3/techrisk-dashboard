<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class NotificationCenter extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationLabel = 'All Notifications';

    protected static ?string $navigationGroup = 'Notifications';

    protected static string $view = 'filament.pages.notification-center';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return Auth::check();
    }

    public function getTableQuery(): Builder
    {
        // Use the DatabaseNotification model directly
        return \Illuminate\Notifications\DatabaseNotification::query()
            ->where('notifiable_id', Auth::id())
            ->where('notifiable_type', get_class(Auth::user()))
            ->latest();
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('data.title')
                    ->label('Title')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn ($record): string => $record->data['message'] ?? ''),

                TextColumn::make('data.type')
                    ->label('Type')
                    ->badge()
                    ->color(fn ($state): string => match($state) {
                        'incident_assignment' => 'danger',
                        'incident_update' => 'info',
                        'incident_status_changed' => 'warning',
                        'action_improvement_reminder' => 'info',
                        'action_improvement_due_soon' => 'warning',
                        'action_improvement_overdue' => 'danger',
                        'new_status_update' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable()
                    ->since(),

                TextColumn::make('read_at')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Read' : 'Unread')
                    ->color(fn ($state): string => $state ? 'success' : 'warning')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record): string => $record->data['url'] ?? '#')
                    ->openUrlInNewTab()
                    ->visible(fn ($record): bool => isset($record->data['url'])),

                Action::make('markAsRead')
                    ->label('Mark as Read')
                    ->icon('heroicon-o-check')
                    ->requiresConfirmation()
                    ->action(fn ($record) => $record->markAsRead())
                    ->visible(fn ($record): bool => is_null($record->read_at)),

                Action::make('markAsUnread')
                    ->label('Mark as Unread')
                    ->icon('heroicon-o-envelope')
                    ->requiresConfirmation()
                    ->action(fn ($record) => $record->markAsUnread())
                    ->visible(fn ($record): bool => !is_null($record->read_at)),

                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn ($record) => $record->delete()),
            ])
            ->bulkActions([
                BulkAction::make('markAsRead')
                    ->label('Mark All as Read')
                    ->icon('heroicon-o-check-circle')
                    ->action(fn (Collection $records) => $records->each->markAsRead()),

                BulkAction::make('markAsUnread')
                    ->label('Mark All as Unread')
                    ->icon('heroicon-o-envelope')
                    ->action(fn (Collection $records) => $records->each->markAsUnread()),

                BulkAction::make('delete')
                    ->label('Delete All')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => $records->each->delete()),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }

    public function getUnreadCount(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }
}
