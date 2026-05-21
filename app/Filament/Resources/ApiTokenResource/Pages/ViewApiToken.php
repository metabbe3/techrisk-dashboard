<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApiTokenResource\Pages;

use App\Enums\ApiEndpoint;
use App\Filament\Resources\ApiTokenResource;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewApiToken extends ViewRecord
{
    protected static string $resource = ApiTokenResource::class;

    public ?string $plainTextToken = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->plainTextToken = session('api_token_plain_'.$record);
        session()->forget('api_token_plain_'.$record);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->record($this->record)
            ->schema([
                Infolists\Components\Section::make('Plain Text Token')
                    ->description('Copy this token now — it will not be shown again')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->visible(fn (): bool => $this->plainTextToken !== null)
                    ->schema([
                        Infolists\Components\TextEntry::make('plainTextToken')
                            ->label('API Token')
                            ->state(fn (): ?string => $this->plainTextToken)
                            ->copyable()
                            ->columnSpanFull(),
                    ]),

                Infolists\Components\Section::make('Token Hidden')
                    ->description('For security, the token value is only shown once after creation or regeneration. Use "Regenerate Token" above if the user lost it.')
                    ->icon('heroicon-o-lock-closed')
                    ->visible(fn (): bool => $this->plainTextToken === null)
                    ->schema([
                        Infolists\Components\TextEntry::make('notice')
                            ->hiddenLabel()
                            ->state('No plain text token available for display.')
                            ->color('warning'),
                    ]),

                Infolists\Components\Section::make('Token Details')
                    ->description('Token configuration and metadata')
                    ->icon('heroicon-o-key')
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label('Token Name'),

                        Infolists\Components\TextEntry::make('tokenable.email')
                            ->label('Account')
                            ->formatStateUsing(function ($record): string {
                                $user = $record->tokenable;

                                return $user?->is_service_account ? '[Service] '.$user->name.' ('.$user->email.')' : $user->name.' ('.$user->email.')';
                            }),

                        Infolists\Components\IconEntry::make('status')
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
                            }),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Configuration')
                    ->schema([
                        Infolists\Components\TextEntry::make('expires_at')
                            ->label('Expiration Date')
                            ->dateTime('M d, Y H:i')
                            ->color(fn ($state): string => $state && $state->isPast() ? 'danger' : ($state && $state->diffInDays(now()) < 30 ? 'warning' : 'success')),

                        Infolists\Components\TextEntry::make('renewal_minutes')
                            ->label('Auto-Renewal')
                            ->formatStateUsing(fn ($state): string => match ((int) $state) {
                                43200 => '30 days per use',
                                86400 => '60 days per use',
                                129600 => '90 days per use',
                                default => $state ? $state.' minutes' : 'None',
                            }),

                        Infolists\Components\TextEntry::make('abilities')
                            ->label('Abilities')
                            ->badge()
                            ->separator(',')
                            ->formatStateUsing(function ($state): string {
                                if (is_string($state)) {
                                    $state = json_decode($state, true) ?? [];
                                }

                                return implode(',', $state ?? []);
                            }),

                        Infolists\Components\TextEntry::make('allowed_endpoints')
                            ->label('Endpoint Access')
                            ->badge()
                            ->color('primary')
                            ->separator(',')
                            ->formatStateUsing(function ($state): string {
                                if (is_string($state)) {
                                    $state = json_decode($state, true) ?? [];
                                }
                                if (empty($state)) {
                                    return 'All Endpoints';
                                }

                                return collect($state)
                                    ->map(fn ($endpoint) => ApiEndpoint::tryFrom($endpoint)?->label() ?? $endpoint)
                                    ->implode(',');
                            }),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Usage')
                    ->schema([
                        Infolists\Components\TextEntry::make('last_used_at')
                            ->label('Last Used')
                            ->dateTime('M d, Y H:i')
                            ->placeholder('Never')
                            ->formatStateUsing(fn ($state) => filled($state) ? $state->format('M d, Y H:i') : 'Never'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime('M d, Y H:i'),
                    ])
                    ->columns(2),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            Action::make('regenerateToken')
                ->label('Regenerate Token')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Regenerate API Token')
                ->modalDescription('This will generate a new token value. The current token will stop working immediately.')
                ->modalSubmitActionLabel('Regenerate')
                ->action(function () {
                    $plainText = bin2hex(random_bytes(32));
                    $this->record->forceFill([
                        'token' => hash('sha256', $plainText),
                    ])->save();

                    $this->plainTextToken = $this->record->id.'|'.$plainText;
                }),

            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Revoke API Token')
                ->modalDescription('Are you sure you want to revoke this token? This action cannot be undone.')
                ->modalSubmitActionLabel('Revoke Token'),
        ];
    }
}
