<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Notifications\AdminAnnouncement;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class SendAnnouncement extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Send Announcement';

    protected static ?string $navigationGroup = 'Notifications';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.send-announcement';

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('admin') ?? false;
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('sendAnnouncement')
                ->label('Send Announcement')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->modalWidth('lg')
                ->form([
                    TextInput::make('title')
                        ->label('Title')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('body')
                        ->label('Message')
                        ->required()
                        ->rows(4)
                        ->maxLength(2000),
                    TextInput::make('url')
                        ->label('Link URL (optional)')
                        ->url()
                        ->maxLength(500),
                    Select::make('target')
                        ->label('Send to')
                        ->required()
                        ->live()
                        ->options([
                            'all' => 'All Users',
                            'admin' => 'Admins Only',
                            'user' => 'Regular Users',
                            'specific' => 'Specific Users',
                        ])
                        ->default('all'),
                    Select::make('user_ids')
                        ->label('Select Users')
                        ->multiple()
                        ->searchable()
                        ->options(User::query()->pluck('name', 'id'))
                        ->visible(fn (Get $get): bool => $get('target') === 'specific')
                        ->required(fn (Get $get): bool => $get('target') === 'specific'),
                    Toggle::make('send_email')
                        ->label('Send as email')
                        ->default(true)
                        ->helperText('When off, only in-app notification is sent'),
                ])
                ->action(function (array $data) {
                    $recipients = match ($data['target']) {
                        'all' => User::all(),
                        'specific' => User::whereIn('id', $data['user_ids'] ?? [])->get(),
                        default => User::whereHas('roles', fn ($q) => $q->where('name', $data['target']))->get(),
                    };

                    $count = 0;

                    foreach ($recipients as $user) {
                        $user->notify(new AdminAnnouncement(
                            title: $data['title'],
                            body: $data['body'],
                            url: $data['url'] ?? null,
                        ));
                        $count++;
                    }

                    Notification::make()
                        ->title('Announcement Sent')
                        ->body("Sent to {$count} user(s).")
                        ->success()
                        ->send();
                }),
        ];
    }
}
