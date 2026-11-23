<?php

declare(strict_types=1);

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use App\Actions\RequestInvestigationForm;
use App\Filament\Actions\ActionImprovementFormSchema;
use App\Models\ActionImprovement;
use App\Models\Category;
use App\Models\Incident;
use App\Models\InvestigationFormRecipient;
use App\Models\InvestigationFormRequest;
use App\Models\User;
use App\Services\InvestigationForm\InvestigationFormSettings;
use App\Services\InvestigationForm\PromoteInvestigationFormActions;
use App\Support\InvestigationFormMailFactory;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                        // Recipients come from the directory (the Entra-synced
                        // user list) — everyone here can SSO, which the intake
                        // now requires. Search-loaded, never all 2.5k rows.
                        Select::make('recipients')
                            ->label('Recipients')
                            ->required()
                            ->multiple()
                            ->searchable()
                            ->maxItems(InvestigationFormSettings::maxRecipients())
                            ->getSearchResultsUsing(function (string $search): array {
                                return User::query()
                                    ->where(fn ($q) => $q->where('is_service_account', false)->orWhereNull('is_service_account'))
                                    ->where(fn ($q) => $q->where('email', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                                    ->orderBy('name')
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn (User $user): array => [$user->email => $user->name.' — '.$user->email])
                                    ->all();
                            })
                            ->getOptionLabelsUsing(function (array $values): array {
                                $names = User::query()->whereIn('email', $values)->pluck('name', 'email');

                                return collect($values)->mapWithKeys(fn (string $email): array => [
                                    $email => $names->has($email) ? $names[$email].' — '.$email : $email,
                                ])->all();
                            })
                            ->hintIcon('heroicon-m-information-circle')
                            ->hint('Picked from the directory — these people sign in with Microsoft.'),
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
                Tables\Actions\Action::make('import_actions')
                    ->label('Import actions')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('primary')
                    ->modalHeading('Import Action Improvements from Investigation Form')
                    ->modalDescription('Review, edit, or remove items extracted from the submitted investigation form before creating Action Improvements on this incident.')
                    ->modalWidth('4xl')
                    ->visible(fn (InvestigationFormRecipient $record): bool => $record->request->status === InvestigationFormRequest::STATUS_SUBMITTED
                        && (bool) auth()->user()?->can('manage incidents')
                        && app(PromoteInvestigationFormActions::class)->draftRows($record->request) !== [])
                    ->fillForm(fn (InvestigationFormRecipient $record): array => [
                        'items' => app(PromoteInvestigationFormActions::class)->draftRows($record->request),
                    ])
                    ->form([
                        Repeater::make('items')
                            ->label('Action Improvements')
                            ->schema([
                                TextInput::make('title')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                Textarea::make('detail')
                                    ->required()
                                    ->rows(2)
                                    ->columnSpanFull(),
                                DatePicker::make('due_date')
                                    ->label('Due date')
                                    ->helperText('Leave empty for TBD.'),
                                Select::make('pic_email')
                                    ->label('PIC')
                                    ->multiple()
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search): array => ActionImprovementFormSchema::picSearchResults($search))
                                    ->getOptionLabelUsing(fn ($value): string => ActionImprovementFormSchema::picOptionLabel($value)),
                                Select::make('priority')
                                    ->options(array_combine(ActionImprovement::PRIORITIES, array_map('ucfirst', ActionImprovement::PRIORITIES)))
                                    ->default('medium')
                                    ->required(),
                                Select::make('status')
                                    ->options([
                                        ActionImprovement::STATUS_PENDING => 'Pending',
                                        ActionImprovement::STATUS_ON_PROGRESS => 'On Progress',
                                        ActionImprovement::STATUS_DRAFT => 'Draft',
                                        ActionImprovement::STATUS_TBD => 'TBD',
                                    ])
                                    ->default(ActionImprovement::STATUS_PENDING)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add action item'),
                    ])
                    ->action(function (InvestigationFormRecipient $record, array $data): void {
                        $created = app(PromoteInvestigationFormActions::class)->import(
                            $record->request->incident,
                            $data['items'] ?? [],
                        );

                        Notification::make()
                            ->title($created->count().' action improvement(s) imported')
                            ->body('They now appear under the Action Improvements tab for this incident.')
                            ->success()
                            ->send();
                    }),
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
                    ->modalDescription('Emails this person\'s link again — the link does not change, so anything already filled stays where it is.')
                    ->visible(fn (InvestigationFormRecipient $record): bool => self::linkState($record) === 'pending')
                    ->action(function (InvestigationFormRecipient $record) {
                        $record->update(['emailed_at' => now()]);

                        $plain = $record->linkToken();
                        ['mail' => $mail, 'link' => $link] = InvestigationFormMailFactory::requestMail($record, $plain, null);
                        Mail::to($record->email)->queue($mail);
                        $emailOff = ! \App\Support\MailSettings::enabled();

                        Notification::make()
                            ->title($emailOff ? 'Email is OFF — copy the link for '.$record->email : 'Link emailed to '.$record->email)
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
                    ->modalDescription('New expiry window, every non-revoked recipient gets their existing link re-emailed.')
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
                            $recipient->update(['emailed_at' => now()]);

                            $plain = $recipient->linkToken();
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
