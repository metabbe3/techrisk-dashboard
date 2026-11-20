<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActionImprovementResource\RelationManagers;

use App\Models\ActionImprovementEvidence;
use App\Services\ActionEvidenceStore;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Evidence files on an action improvement — encrypt-on-upload clone of the
 * supporting-documents flow (InvestigationDocumentsRelationManager), minus
 * markdown conversion, AI actions and edit/replace: evidence is immutable,
 * delete + re-upload instead. The encrypt/store/audit flow lives in
 * ActionEvidenceStore so this page and the shared Submit Proof action
 * (team-viewers) cannot drift.
 */
class EvidenceRelationManager extends RelationManager
{
    protected static string $relationship = 'evidence';

    protected static ?string $title = 'Evidence';

    public function form(Form $form): Form
    {
        return $form->schema([
            FileUpload::make('file_path')
                ->label('Evidence')
                ->storeFiles(false)
                ->downloadable()
                ->maxSize(15360) // 15MB
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'text/plain', 'application/pdf'])
                ->required(fn (string $context): bool => $context === 'create'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_filename')
            ->columns([
                Tables\Columns\TextColumn::make('original_filename')
                    ->label('File')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('link_url')
                    ->label('Link')
                    ->limit(50)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('approved_at')
                    ->label('Approved')
                    ->dateTime()
                    ->placeholder('Awaiting approval'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->using(function (array $data): Model {
                        $fileInput = $data['file_path'] ?? null;

                        if (is_array($fileInput)) {
                            $fileInput = reset($fileInput);
                        }

                        try {
                            $evidence = app(ActionEvidenceStore::class)->store($this->ownerRecord, $fileInput, auth()->id());
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('File upload failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'file_path' => 'File upload failed: '.$e->getMessage(),
                            ]);
                        }

                        return $evidence;
                    })
                    ->successNotification(
                        Notification::make()
                            ->title('Evidence uploaded successfully')
                            ->success(),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->label('Download')
                    // Link rows have no file behind them — "Open link" below.
                    ->visible(fn (ActionImprovementEvidence $record): bool => filled($record->file_path))
                    ->action(fn ($record) => redirect()->route('action-evidence.download', $record)),
                Tables\Actions\Action::make('open_link')
                    ->label('Open link')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (ActionImprovementEvidence $record): bool => filled($record->link_url))
                    ->url(fn (ActionImprovementEvidence $record): ?string => $record->link_url, shouldOpenInNewTab: true),
                Tables\Actions\DeleteAction::make()
                    ->after(function (ActionImprovementEvidence $record) {
                        if ($record->file_path && Storage::disk('public')->exists($record->file_path)) {
                            Storage::disk('public')->delete($record->file_path);
                        }
                        $record->evidenceKey()->delete();

                        try {
                            $this->ownerRecord->incident->audits()->create([
                                'user_id' => auth()->id(),
                                'event' => $record->file_path ? 'file_deleted' : 'link_deleted',
                                'auditable_type' => \App\Models\Incident::class,
                                'auditable_id' => $this->ownerRecord->incident->id,
                                'old_values' => [
                                    'filename' => (string) \Illuminate\Support\Str::of($record->original_filename ?? $record->link_url)->ascii(),
                                ],
                            ]);
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error('Error creating audit log: '.$e->getMessage());
                        }
                    }),
            ]);
    }
}
