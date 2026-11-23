<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\ActionImprovement;
use App\Models\AiSetting;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\ActionImprovementProofApproved;
use App\Notifications\ActionImprovementProofSubmitted;
use App\Services\ActionEvidenceStore;
use App\Services\ActionImprovements\ActionRewordService;
use App\Services\Ai\AiTextService;
use App\Support\ReminderMail;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\DB;

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
                // No preload: the directory sync brought 2.5k+ humans — options
                // are served by server-side search instead (top 50 hits).
                ->getSearchResultsUsing(fn (string $search): array => self::picSearchResults($search))
                ->getOptionLabelUsing(fn ($value): string => self::picOptionLabel($value))
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
     * Directory search behind BOTH PIC pickers (the form Select and the
     * Add Assignee action) — one home so the two cannot drift.
     */
    public static function picSearchResults(string $search): array
    {
        return User::humanUsers()
            ->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('department', 'like', "%{$search}%")
                ->orWhere('job_title', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($u) => [$u->email => $u->name])
            ->all();
    }

    public static function picOptionLabel($value): string
    {
        return (string) (User::where('email', $value)->value('name') ?? $value);
    }

    /**
     * Shared "Add Assignee" row action (owner 2026-11-20 re-assignment):
     * the action's own assignees + trusted users can ADD people to
     * pic_email (add-only — removal stays with Edit for admins). The
     * observer emails ONLY the newly-added assignees.
     */
    public static function addAssigneeAction(): Action
    {
        return Action::make('add_assignee')
            ->label('Add Assignee')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->visible(fn (ActionImprovement $record): bool => $record->isOpenWork()
                && self::mayManageAssignees($record))
            ->form([
                Forms\Components\Select::make('new_pic_email')
                    ->label('Add person in charge')
                    ->multiple()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::picSearchResults($search))
                    ->getOptionLabelUsing(fn ($value): string => self::picOptionLabel($value))
                    ->helperText('They will be emailed that they are now responsible for this action.'),
            ])
            ->action(function (ActionImprovement $record, array $data) {
                self::guardManageAssignees($record);

                $added = collect($data['new_pic_email'] ?? [])
                    ->filter()
                    ->unique()
                    ->reject(fn (string $email) => in_array($email, $record->pic_email ?? [], true))
                    ->values()
                    ->all();

                if ($added === []) {
                    Notification::make()
                        ->title('Nothing to add')
                        ->body('Those people are already assigned to this action.')
                        ->warning()
                        ->send();

                    return;
                }

                $record->pic_email = [...($record->pic_email ?? []), ...$added];
                $record->save(); // observer upserts directory rows + emails the newly-added only

                Notification::make()
                    ->title('Assignee added')
                    ->body(count($added).' person(s) added and notified.')
                    ->success()
                    ->send();
            });
    }

    /** Add-assignee grant: trusted approvers (admin / incident PIC) + the action's own assignees. */
    public static function mayManageAssignees(ActionImprovement $record): bool
    {
        $user = auth()->user();

        return $user !== null && (
            self::isTrustedApprover($record)
            || in_array($user->email, $record->pic_email ?? [], true)
        );
    }

    /** visible() is UX only — re-checked server-side before any write. */
    public static function guardManageAssignees(ActionImprovement $record): void
    {
        abort_unless(
            $record->isOpenWork() && self::mayManageAssignees($record),
            403,
        );
    }

    /**
     * Owner 2026-11-20 two-PIC model: admins + the incident's PICs are
     * TRUSTED (may self-close a proof submission and approve workers'
     * submissions); the action's pic_email assignees are WORKERS — their
     * submissions always await approval. The incident-PIC match also
     * transitively satisfies proofAllowed's scope, so no second query.
     */
    public static function isTrustedApprover(ActionImprovement $record): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->hasRole('admin')
            || $record->incident->pics()->whereKey($user->getAuthIdentifier())->exists();
    }

    /**
     * Shared "Submit Proof" row action (team-viewer role, owner 2026-11-20):
     * proof = file AND/OR link (chat / Notion URL), encrypted at rest for
     * files. Trusted submitters (admin / incident PIC) may self-close via
     * mark_done; worker submissions land pending-approval — the action's
     * status is UNCHANGED so reminders deliberately keep chasing until an
     * Approve Proof. Lives here so the relation manager and the global
     * resource cannot drift (same reason as rewordAction).
     */
    public static function submitProofAction(): Action
    {
        return Action::make('submit_proof')
            ->label('Submit Proof')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->visible(fn ($record): bool => $record->isOpenWork()
                && (
                    (bool) auth()->user()?->can('submit action proof')
                    || self::isTrustedApprover($record)
                )
                && self::proofAllowed($record))
            ->form([
                Forms\Components\FileUpload::make('file_path')
                    ->label('Proof of completion')
                    ->helperText('Screenshot or document — required unless a proof link is provided.')
                    ->storeFiles(false)
                    ->maxSize(15360) // 15MB
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'text/plain', 'application/pdf'])
                    ->required(fn (Forms\Get $get): bool => blank($get('link_url'))),
                Forms\Components\TextInput::make('link_url')
                    ->label('Proof link (URL)')
                    ->helperText('HTTPS link to a chat message / Notion page — required unless a file is provided.')
                    ->url()
                    // W4: only https links are accepted at store time; the
                    // download route also re-checks before redirecting.
                    ->rules(['url', 'starts_with:https://'])
                    ->maxLength(2048)
                    ->required(fn (Forms\Get $get): bool => blank($get('file_path'))),
                Forms\Components\Textarea::make('result_note')
                    ->label('Result note')
                    ->helperText('What was done / why it is safe to close.'),
                Forms\Components\Checkbox::make('mark_done')
                    ->label('Mark this action Done (stops reminders/escalation)')
                    ->default(true)
                    // Trusted only. Hidden Filament fields carry no data, but
                    // the closure re-checks trusted-ness server-side anyway —
                    // a forged Livewire call could still inject mark_done.
                    ->visible(fn (ActionImprovement $record): bool => self::isTrustedApprover($record)),
            ])
            ->action(function ($record, array $data) {
                self::guardSubmitProof($record);

                $trusted = self::isTrustedApprover($record);
                $selfClose = $trusted && ($data['mark_done'] ?? false);

                $fileInput = $data['file_path'] ?? null;

                if (is_array($fileInput)) {
                    $fileInput = reset($fileInput);
                }

                $linkUrl = filled($data['link_url'] ?? null) ? trim((string) $data['link_url']) : null;

                $created = [];

                try {
                    if ($fileInput !== null) {
                        $created[] = app(ActionEvidenceStore::class)
                            ->store($record, $fileInput, auth()->id(), pendingApproval: ! $selfClose);
                    }

                    if ($linkUrl !== null) {
                        $created[] = app(ActionEvidenceStore::class)
                            ->storeLink($record, $linkUrl, auth()->id(), pendingApproval: ! $selfClose);
                    }
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('Proof submission failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                if ($selfClose) {
                    // A done action must never carry "awaiting approval"
                    // evidence — the trusted row is approved at creation.
                    foreach ($created as $evidence) {
                        $evidence->forceFill(['approved_at' => now(), 'approved_by' => auth()->id()])->saveQuietly();
                    }

                    $record->status = ActionImprovement::STATUS_DONE;
                    $record->result_note = $data['result_note'] ?? null;
                    $record->save(); // observer stamps completed_at + completed_by

                    Notification::make()
                        ->title('Proof submitted — action marked Done')
                        ->success()
                        ->send();

                    return;
                }

                $record->result_note = $data['result_note'] ?? null;
                $record->save();

                self::notifyProofApprovers($record, $created);

                Notification::make()
                    ->title('Proof submitted — awaiting approval')
                    ->body('The incident PICs and admins have been notified.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Approver notifications for a worker submission: per-user in-app for
     * every approver + ONE combined email to all of them (owner 2026-10-09
     * multi-TO rule) — mail never rides the per-user notify() channel here.
     * Kill-switch/suppression stamps nothing and rolls nothing back: the
     * evidence rows already persist.
     *
     * @param  array<int, ActionImprovementEvidence>  $created
     */
    private static function notifyProofApprovers(ActionImprovement $record, array $created): void
    {
        $fileNames = collect($created)
            ->map(fn ($e) => $e->original_filename)
            ->filter()
            ->all();
        $linkUrls = collect($created)
            ->map(fn ($e) => $e->link_url)
            ->filter()
            ->all();

        $notification = new ActionImprovementProofSubmitted(
            $record,
            (string) auth()->user()?->name,
            $fileNames,
            $linkUrls,
        );

        $approvers = $record->incident->pics
            ->merge(User::role('admin')->get())
            ->reject(fn (User $u) => $u->id === auth()->id())
            ->unique('id')
            ->values();

        $approvers->each(fn (User $user) => $user->notify($notification));
        ReminderMail::send($approvers, $notification);
    }

    /**
     * Shared "Approve Proof" row action (owner 2026-11-20): stamps ALL
     * pending evidence approved and closes the action (status Done —
     * reminders/escalation stop via openStatuses), then notifies each
     * submitter. No rejection flow v1 — PICs can delete evidence or edit
     * the status manually instead.
     */
    public static function approveProofAction(): Action
    {
        return Action::make('approve_proof')
            ->label('Approve Proof')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Marks every pending proof approved and closes the action (status Done — reminders and escalation stop). The submitter is notified.')
            ->visible(fn (ActionImprovement $record): bool => $record->isOpenWork()
                && $record->hasPendingProof()
                && self::isTrustedApprover($record))
            ->action(function (ActionImprovement $record) {
                self::guardApproveProof($record);

                // Stamp-then-save must be atomic: if they can part, the
                // pending marker is gone while the action is still open —
                // hasPendingProof() false, Approve button vanished.
                DB::transaction(function () use ($record) {
                    $record->evidence()
                        ->whereNotNull('submitted_by')
                        ->whereNull('approved_at')
                        ->update([
                            'approved_at' => now(),
                            'approved_by' => auth()->id(),
                        ]);

                    $record->status = ActionImprovement::STATUS_DONE;
                    $record->save(); // observer stamps completed_at + completed_by = approver
                });

                User::whereIn('id', $record->evidence()
                    ->whereNotNull('submitted_by')
                    ->distinct()
                    ->pluck('submitted_by'))
                    ->each(fn (User $user) => $user->notify(new ActionImprovementProofApproved($record)));

                Notification::make()
                    ->title('Proof approved')
                    ->body('Action marked Done — the submitter has been notified.')
                    ->success()
                    ->send();
            });
    }

    /**
     * visible() is UX only — the approve grant is re-checked server-side
     * before anything is written (identity-based: admin or incident PIC).
     */
    public static function guardApproveProof(ActionImprovement $record): void
    {
        abort_unless(
            $record->isOpenWork()
            && $record->hasPendingProof()
            && self::isTrustedApprover($record),
            403,
        );
    }

    /**
     * visible() is UX only — the grant is re-checked server-side before
     * anything is written (proofAllowed = the ONE incident scope, so
     * out-of-scope actions abort 403 even via direct action calls). Workers
     * hold `submit action proof`; trusted approvers (admin / incident PIC)
     * ride identity instead — the PIC-of-the-incident may not carry the
     * permission role-side.
     */
    public static function guardSubmitProof($record): void
    {
        abort_unless(
            (
                (bool) auth()->user()?->can('submit action proof')
                || self::isTrustedApprover($record)
            )
            && self::proofAllowed($record),
            403,
        );
    }

    /**
     * The team-viewer's grant for one action: the parent incident must pass
     * the shared access scope (the same logic as the incident list, so a
     * forged action call can never exceed what the list shows).
     */
    public static function proofAllowed($record): bool
    {
        return (bool) Incident::query()
            ->whereKey($record->incident_id)
            ->applyUserYearAccess(auth()->user())
            ->exists();
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
