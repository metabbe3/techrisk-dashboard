<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\ActionImprovement;
use App\Models\AiSetting;
use App\Models\User;
use App\Services\ActionImprovements\ActionRewordService;
use App\Services\Ai\AiTextService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;

/**
 * Single source for the action-improvement form (PROJ-014) — consumed by the
 * incident relation manager and the global ActionImprovementResource.
 * ExportActionSchema precedent: one class, shared everywhere.
 */
class ActionImprovementFormSchema
{
    /**
     * @return array<Forms\Components\Component>
     */
    public static function schema(): array
    {
        return [
            Forms\Components\TextInput::make('title')
                ->required()
                ->maxLength(255),
            Forms\Components\Textarea::make('detail')
                ->required()
                ->columnSpanFull(),
            Forms\Components\DatePicker::make('due_date')
                // Empty = TBD (owner 2026-10-14) — nullable column, shows TBD in tables.
                ->helperText('Leave empty when the due date is not decided yet — the row shows TBD.'),
            Forms\Components\Select::make('pic_email')
                ->label('Person In Charge')
                ->required()
                ->multiple()
                ->searchable()
                ->preload()
                // Keyed by email — pic_email keeps storing bare emails.
                ->options(fn () => User::humanUsers()->orderBy('name')->get()
                    ->mapWithKeys(fn ($u) => [$u->email => $u->name]))
                ->createOptionModalHeading('Add new PIC contact')
                ->createOptionForm([
                    Forms\Components\TextInput::make('name'),
                    Forms\Components\TextInput::make('email')->email()->required(),
                ])
                // Directory contact, login-disabled (single home:
                // User::upsertFromPicEmails — flip can_login to grant access).
                ->createOptionUsing(function (array $data): string {
                    $tag = filled($data['name'] ?? null) ? "{$data['name']} <{$data['email']}>" : $data['email'];

                    return User::upsertFromPicEmails([$tag])[0];
                })
                ->helperText('Search by name — or use + to add a new contact (no login access until enabled in User Management).'),
            Forms\Components\Select::make('priority')
                ->options(array_combine(ActionImprovement::PRIORITIES, array_map('ucfirst', ActionImprovement::PRIORITIES)))
                // Optional everywhere, default low (owner rule 2026-10-14).
                ->default('low'),
            Forms\Components\Toggle::make('reminder'),
            Forms\Components\Select::make('reminder_frequency')
                ->options([
                    'weekly' => 'Once a week',
                    'biweekly' => 'Once every 2 weeks',
                ]),
            Forms\Components\Select::make('status')
                ->options(self::statusOptions())
                ->default(ActionImprovement::STATUS_PENDING)
                ->required()
                ->live(),
            Forms\Components\Textarea::make('result_note')
                ->label('Result note')
                ->helperText('What was done / why it is safe to close.')
                ->visible(fn (Forms\Get $get): bool => $get('status') === ActionImprovement::STATUS_DONE)
                ->columnSpanFull(),
            // Cancel reason is mandatory (owner 2026-10-26): hidden Filament
            // fields are not validated, so required() only bites when the
            // field is visible — same mechanism as result_note above.
            Forms\Components\Textarea::make('cancellation_reason')
                ->label('Cancellation reason')
                ->helperText('Why this action is not applicable.')
                ->visible(fn (Forms\Get $get): bool => $get('status') === ActionImprovement::STATUS_CANCELLED)
                ->required()
                ->columnSpanFull(),
        ];
    }

    /**
     * Shared "Reword (AI)" row action (PROJ-016 iter 3, owner 2026-10-14):
     * rewords a DRAFT's title + detail into professional English with more
     * detail. Lives here so the relation manager and the global resource
     * cannot drift (same reason as the schema and the color maps).
     */
    public static function rewordAction(): Action
    {
        return Action::make('reword')
            ->label('Reword (AI)')
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription('Rewrite the title and detail in professional English with more detail. The meaning is preserved; PIC, due date, priority and status are not changed.')
            ->visible(fn ($record): bool => $record->status === ActionImprovement::STATUS_DRAFT
                && app(AiTextService::class)->isAvailable()
                && (bool) auth()->user()?->can('manage incidents'))
            ->form(function () {
                $models = app(AiTextService::class)->getModelsForPicker();
                $default = AiSetting::get('default_model', config('ai.default_model', 'SMART-MODEL'));

                return [
                    Forms\Components\Select::make('model')
                        ->label('AI Model')
                        ->options($models)
                        ->default(isset($models[$default]) ? $default : array_key_first($models))
                        ->required(),
                ];
            })
            ->action(function ($record, array $data) {
                app(ActionRewordService::class)->reword($record, $data['model'])
                    ? Notification::make()
                        ->title('Reworded')
                        ->body('Review the title and detail before promoting the draft.')
                        ->success()
                        ->send()
                    : Notification::make()
                        ->title('Reword failed')
                        ->body('The model returned nothing usable. Try another model.')
                        ->danger()
                        ->send();
            });
    }

    /**
     * Shared status option map — the form Select, the global resource's
     * status filter and the relation manager's status filter all read THIS
     * (the hand-typed filter map drifted once; same reason as statusColor).
     */
    public static function statusOptions(): array
    {
        return [
            ActionImprovement::STATUS_DRAFT => 'Draft (AI-suggested)',
            ActionImprovement::STATUS_PENDING => 'Pending',
            ActionImprovement::STATUS_ON_PROGRESS => 'On progress',
            ActionImprovement::STATUS_DONE => 'Done',
            ActionImprovement::STATUS_CANCELLED => 'Cancelled (not applicable)',
            // Status parking state — distinct from due_date = null ("TBD" date).
            ActionImprovement::STATUS_TBD => 'TBD (to be discussed)',
        ];
    }

    /** Shared badge colors — the relation manager and global resource must not drift. */
    public static function statusColor(string $state): string
    {
        return match ($state) {
            ActionImprovement::STATUS_DONE => 'success',
            ActionImprovement::STATUS_PENDING => 'warning',
            ActionImprovement::STATUS_ON_PROGRESS => 'info',
            ActionImprovement::STATUS_CANCELLED => 'danger',
            ActionImprovement::STATUS_TBD => 'gray', // parked, like draft
            default => 'gray', // draft
        };
    }

    public static function priorityColor(string $state): string
    {
        return match ($state) {
            'critical' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            default => 'gray', // low
        };
    }
}
