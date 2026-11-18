<?php

declare(strict_types=1);

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use App\Actions\RequestInvestigationForm;
use App\Models\Category;
use App\Models\Incident;
use App\Models\InvestigationFormRecipient;
use App\Models\InvestigationFormRequest;
use App\Services\InvestigationForm\InvestigationFormSettings;
use App\Support\InvestigationFormMailFactory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;

/**
 * "Investigation Forms" on ViewIncident: one row per recipient (grouped by
 * team). Request-level operations (view answers / downloads / reissue /
 * revoke request) ride on the rows of that request.
 */
class InvestigationFormsRelationManager extends RelationManager
{
    protected static string $relationship = 'investigationFormRecipients';

    protected static ?string $title = 'Incident Investigation Forms';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->defaultGroup(Group::make('request.team')->label('Team'))
            ->columns([
                Tables\Columns\TextColumn::make('email')->label('Recipient')->copyable(),
                Tables\Columns\TextColumn::make('link_state')
                    ->label('Link')
                    ->badge()
                    ->state(fn (InvestigationFormRecipient $record): string => self::linkState($record))
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'submitted' => 'success',
                        'revoked' => 'danger',
                        'expired' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('request.expires_at')->label('Expires')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('last_opened_at')->label('Opened')->since()->placeholder('—'),
                Tables\Columns\TextColumn::make('emailed_at')->label('Emailed')->since()->placeholder('—'),
                Tables\Columns\TextColumn::make('reminder_count')->placeholder('0'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make('request')
                    ->label('Request form')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('manage incidents'))
                    ->form([
                        Select::make('team')
                            ->label('Team')
                            ->options(fn (RelationManager $livewire): array => self::teamOptions($livewire->getOwnerRecord()))
                            ->required()
                            ->searchable(),
                        TagsInput::make('recipients')
                            ->label('Recipients')
                            ->placeholder('name@example.com')
                            ->required()
                            // Filament v3 TagsInput has no maxItems/email() — the
                            // cap + email format are enforced in the action.
                            ->rule(fn (): string => 'max:'.InvestigationFormSettings::maxRecipients()),
                        Textarea::make('note')
                            ->label('Note for the recipients')
                            ->maxLength(1000),
                    ])
                    ->using(function (array $data, RelationManager $livewire): Model {
                        $result = app(RequestInvestigationForm::class)->handle(
                            $livewire->getOwnerRecord(),
                            $data['team'],
                            $data['recipients'],
                            $data['note'] ?? null,
                            auth()->user(),
                        );

                        $emailOff = ! \App\Support\MailSettings::enabled();

                        foreach ($result['mailed'] as $email => $link) {
                            Notification::make()
                                ->title($emailOff ? 'Email is OFF — copy the link for '.$email : 'Link emailed to '.$email)
                                ->body($emailOff ? 'The global email kill-switch is on. Copy this one-time link and share it directly:' : 'One-time copy fallback if mail is down:')
                                ->actions([
                                    NotificationAction::make('copy')
                                        ->url($link, shouldOpenInNewTab: false)
                                        ->button(),
                                ])
                                ->success()
                                ->persistent()
                                ->send();
                        }

                        if ($result['skipped'] !== []) {
                            Notification::make()
                                ->title('Already on this request (not re-emailed): '.implode(', ', $result['skipped']))
                                ->warning()
                                ->send();
                        }

                        return $result['request']->recipients()->where('email', array_key_first($result['mailed']) ?? '')->first() ?? $result['request']->recipients()->first();
                    }),
            ])->actions([
                Tables\Actions\Action::make('view_answers')
                    ->label('View answers')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (InvestigationFormRecipient $record): bool => $record->request->status === InvestigationFormRequest::STATUS_SUBMITTED)
                    ->modalContent(fn (InvestigationFormRecipient $record) => view('filament.incident-resource.investigation-form-answers', ['request' => $record->request])),
                Tables\Actions\Action::make('download_docx')
                    ->label('DOCX')
                    ->icon('heroicon-o-document-arrow-down')
                    ->visible(fn (InvestigationFormRecipient $record): bool => $record->request->docx_document_id !== null)
                    ->url(fn (InvestigationFormRecipient $record): string => route('documents.download', $record->request->docx_document_id), shouldOpenInNewTab: true),
                Tables\Actions\Action::make('download_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->visible(fn (InvestigationFormRecipient $record): bool => $record->request->pdf_document_id !== null)
                    ->url(fn (InvestigationFormRecipient $record): string => route('documents.download', $record->request->pdf_document_id), shouldOpenInNewTab: true),
                Tables\Actions\Action::make('resend')
                    ->label('Resend')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalHeading('Resend link')
                    ->modalDescription('Rotates this person\'s token — their previous link stops working — and emails the fresh link.')
                    ->visible(fn (InvestigationFormRecipient $record): bool => self::linkState($record) === 'pending')
                    ->action(function (InvestigationFormRecipient $record) {
                        $plain = InvestigationFormRecipient::newToken();
                        $record->update([
                            'token_hash' => InvestigationFormRecipient::hashToken($plain),
                            'emailed_at' => now(),
                        ]);

                        ['mail' => $mail, 'link' => $link] = InvestigationFormMailFactory::requestMail($record, $plain, null);
                        Mail::to($record->email)->queue($mail);
                        $emailOff = ! \App\Support\MailSettings::enabled();

                        Notification::make()
                            ->title($emailOff ? 'Email is OFF — copy the link for '.$record->email : 'Link rotated and re-emailed to '.$record->email)
                            ->body($emailOff ? 'The global email kill-switch is on. Copy this one-time link and share it directly:' : 'One-time copy fallback if mail is down:')
                            ->actions([NotificationAction::make('copy')->url($link)->button()])
                            ->success()
                            ->persistent()
                            ->send();
                    }),
                Tables\Actions\Action::make('revoke_person')
                    ->label('Revoke person')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Revoke this person\'s link')
                    ->visible(fn (InvestigationFormRecipient $record): bool => self::linkState($record) === 'pending')
                    ->action(fn (InvestigationFormRecipient $record) => $record->update(['revoked_at' => now()])),
                Tables\Actions\Action::make('reissue')
                    ->label('Reissue')
                    ->icon('heroicon-o-arrow-uturn-right')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reissue the team\'s request')
                    ->modalDescription('New expiry window, every non-revoked recipient gets a rotated token and a fresh email.')
                    ->visible(fn (InvestigationFormRecipient $record): bool => in_array(self::linkState($record), ['expired', 'revoked'], true))
                    ->action(function (InvestigationFormRecipient $record) {
                        $request = $record->request;
                        $request->update([
                            'status' => InvestigationFormRequest::STATUS_PENDING,
                            'expires_at' => now()->addDays(InvestigationFormSettings::expiryDays()),
                        ]);

                        foreach ($request->recipients()->whereNotNull('revoked_at')->get() as $recipient) {
                            $recipient->update(['revoked_at' => null]);
                        }

                        foreach ($request->recipients()->whereNull('revoked_at')->get() as $recipient) {
                            $plain = InvestigationFormRecipient::newToken();
                            $recipient->update([
                                'token_hash' => InvestigationFormRecipient::hashToken($plain),
                                'emailed_at' => now(),
                            ]);

                            ['mail' => $mail, 'link' => $link] = InvestigationFormMailFactory::requestMail($recipient, $plain, null);
                            Mail::to($recipient->email)->queue($mail);

                            Notification::make()
                                ->title(! \App\Support\MailSettings::enabled() ? 'Email is OFF — copy the link for '.$recipient->email : 'Reissued link emailed to '.$recipient->email)
                                ->actions([NotificationAction::make('copy')->url($link)->button()])
                                ->success()
                                ->persistent()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('revoke_request')
                    ->label('Revoke request')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Revoke the whole request')
                    ->modalDescription('Every recipient\'s link stops working immediately.')
                    ->visible(fn (InvestigationFormRecipient $record): bool => self::linkState($record) === 'pending')
                    ->action(fn (InvestigationFormRecipient $record) => $record->request->update(['status' => InvestigationFormRequest::STATUS_REVOKED])),
            ])
            ->bulkActions([]);
    }

    public static function linkState(InvestigationFormRecipient $record): string
    {
        return match (true) {
            $record->request->status === InvestigationFormRequest::STATUS_SUBMITTED => 'submitted',
            $record->isRevoked() || $record->request->status === InvestigationFormRequest::STATUS_REVOKED => 'revoked',
            $record->request->expires_at !== null && $record->request->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    /** @return array<string, string> */
    private static function teamOptions(Incident $incident): array
    {
        $teams = array_values(array_filter((array) ($incident->responsible_team ?? [])));

        if ($teams === []) {
            $teams = Category::where('type', Category::TYPE_RESPONSIBLE_TEAM)->pluck('name')->all();
        }

        return array_combine($teams, $teams);
    }
}
