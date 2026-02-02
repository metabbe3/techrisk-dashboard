<?php

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use App\Models\Incident;
use App\Services\EncryptionService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvestigationDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'investigationDocuments';

    protected static ?string $title = 'Supporting Documents';

    private array $encryptionData = [];

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('description')->required(),
            TextInput::make('pic_status')->label('PIC & Status'),
            FileUpload::make('file_path')
                ->label('Document')
                ->storeFiles(false)
                ->downloadable()
                ->maxSize(15360) // 15MB
                ->required(fn (string $context): bool => $context === 'create'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                Tables\Columns\TextColumn::make('description'),
                Tables\Columns\TextColumn::make('original_filename')
                    ->label('Document'),
                Tables\Columns\TextColumn::make('markdown_conversion_status')
                    ->label('Markdown')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        'completed' => 'success',
                        'processing' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data, EncryptionService $encryptionService): array {
                        try {
                            $fileInput = $data['file_path'] ?? null;

                            if (is_array($fileInput)) {
                                $fileInput = reset($fileInput);
                            }

                            if ($fileInput instanceof UploadedFile) {
                                $key = $encryptionService->generateKey();
                                $salt = $encryptionService->generateSalt();
                                $method = 'method'.rand(1, 3);
                                $finalKey = $encryptionService->getFinalKey($key, $salt, $method);

                                $this->encryptionData = [
                                    'key' => $key,
                                    'salt' => $salt,
                                    'method' => $method,
                                    'original_filename' => $fileInput->getClientOriginalName(),
                                ];

                                $encryptedContent = $encryptionService->encrypt($fileInput->get(), $finalKey);
                                $directory = 'investigation-forms';
                                $newFileName = $directory.'/'.Str::uuid().'.encrypted';

                                Storage::disk('public')->makeDirectory($directory);
                                Storage::disk('public')->put($newFileName, $encryptedContent);

                                $data['file_path'] = $newFileName;
                                $data['original_filename'] = $fileInput->getClientOriginalName();
                            }
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

                        return $data;
                    })
                    ->after(function (Model $record) {
                        if (! empty($this->encryptionData)) {
                            $record->encryptionKey()->create($this->encryptionData);

                            try {
                                $this->ownerRecord->audits()->create([
                                    'user_id' => Auth::id(),
                                    'event' => 'file_uploaded',
                                    'auditable_type' => Incident::class,
                                    'auditable_id' => $this->ownerRecord->id,
                                    'new_values' => [
                                        'filename' => (string) Str::of($this->encryptionData['original_filename'])->ascii(),
                                    ],
                                ]);
                            } catch (\Exception $e) {
                                Log::error('Error creating audit log: '.$e->getMessage());
                            }

                            // Dispatch markdown conversion job
                            \App\Jobs\ConvertDocumentToMarkdown::dispatch($record);

                            $this->encryptionData = [];
                            Notification::make()
                                ->title('Document uploaded successfully')
                                ->body('Document queued for Markdown conversion.')
                                ->success()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('download_markdown')
                    ->icon('heroicon-o-document-arrow-down')
                    ->label('Download MD')
                    ->color('success')
                    ->visible(fn ($record): bool => $record->markdown_path !== null)
                    ->action(function ($record) {
                        if (! $record->markdown_path) {
                            return null;
                        }

                        $markdown = Storage::disk('local')->get($record->markdown_path);
                        $filename = pathinfo($record->original_filename, PATHINFO_FILENAME).'.md';

                        return response()->streamDownload(
                            function () use ($markdown) {
                                echo $markdown;
                            },
                            $filename,
                            ['Content-Type' => 'text/markdown; charset=utf-8']
                        );
                    }),
                Tables\Actions\Action::make('reconvert')
                    ->icon('heroicon-o-arrow-path')
                    ->label('Reconvert')
                    ->color('warning')
                    ->action(function ($record) {
                        $record->update(['markdown_conversion_status' => 'pending']);
                        \App\Jobs\ConvertDocumentToMarkdown::dispatch($record);
                        Notification::make()
                            ->title('Document requeued for conversion')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function ($record, EncryptionService $encryptionService): ?StreamedResponse {
                        if (! $record->file_path || ! Storage::disk('public')->exists($record->file_path)) {
                            return null;
                        }

                        $encryptionKey = $record->encryptionKey;
                        if (! $encryptionKey) {
                            return null;
                        }

                        try {
                            $finalKey = $encryptionService->getFinalKey($encryptionKey->key, $encryptionKey->salt, $encryptionKey->method);
                            $decryptedContent = $encryptionService->decrypt(Storage::disk('public')->get($record->file_path), $finalKey);
                        } catch (\Exception $e) {
                            return null;
                        }

                        try {
                            $this->ownerRecord->audits()->create([
                                'user_id' => Auth::id(),
                                'event' => 'file_downloaded',
                                'auditable_type' => Incident::class,
                                'auditable_id' => $this->ownerRecord->id,
                                'new_values' => [
                                    'filename' => (string) Str::of($record->original_filename)->ascii(),
                                ],
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Error creating audit log: '.$e->getMessage());
                        }

                        return response()->streamDownload(
                            function () use ($decryptedContent) {
                                echo $decryptedContent;
                            },
                            $record->original_filename
                        );
                    }),
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(function (Tables\Actions\EditAction $action, array $data, EncryptionService $encryptionService): array {
                        try {
                            $fileInput = $data['file_path'] ?? null;

                            if (is_array($fileInput)) {
                                $fileInput = reset($fileInput);
                            }

                            if ($fileInput instanceof UploadedFile) {
                                $record = $action->getRecord();
                                $this->encryptionData['old_filename'] = $record->original_filename;

                                // Delete old file and encryption key
                                if ($record->file_path && Storage::disk('public')->exists($record->file_path)) {
                                    Storage::disk('public')->delete($record->file_path);
                                }
                                $record->encryptionKey()->delete();

                                // Encrypt new file
                                $key = $encryptionService->generateKey();
                                $salt = $encryptionService->generateSalt();
                                $method = 'method'.rand(1, 3);
                                $finalKey = $encryptionService->getFinalKey($key, $salt, $method);

                                $this->encryptionData = array_merge($this->encryptionData, [
                                    'key' => $key,
                                    'salt' => $salt,
                                    'method' => $method,
                                    'original_filename' => $fileInput->getClientOriginalName(),
                                ]);

                                $encryptedContent = $encryptionService->encrypt($fileInput->get(), $finalKey);
                                $directory = 'investigation-forms';
                                $newFileName = $directory.'/'.Str::uuid().'.encrypted';

                                Storage::disk('public')->makeDirectory($directory);
                                Storage::disk('public')->put($newFileName, $encryptedContent);

                                $data['file_path'] = $newFileName;
                                $data['original_filename'] = $fileInput->getClientOriginalName();

                            } else {
                                $record = $action->getRecord();
                                if ($record) {
                                    $data['file_path'] = $record->file_path;
                                    $data['original_filename'] = $record->original_filename;
                                }
                            }
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

                        return $data;
                    })
                    ->after(function (Model $record) {
                        if (! empty($this->encryptionData)) {
                            $record->encryptionKey()->create($this->encryptionData);

                            try {
                                $this->ownerRecord->audits()->create([
                                    'user_id' => Auth::id(),
                                    'event' => 'file_updated',
                                    'auditable_type' => Incident::class,
                                    'auditable_id' => $this->ownerRecord->id,
                                    'new_values' => [
                                        'filename' => (string) Str::of($this->encryptionData['original_filename'])->ascii(),
                                    ],
                                    'old_values' => [
                                        'filename' => (string) Str::of($this->encryptionData['old_filename'])->ascii(),
                                    ],
                                ]);
                            } catch (\Exception $e) {
                                Log::error('Error creating audit log: '.$e->getMessage());
                            }

                            $this->encryptionData = [];
                            Notification::make()
                                ->title('Document updated successfully')
                                ->success()
                                ->send();
                        }
                    }),
                Tables\Actions\DeleteAction::make()
                    ->after(function ($record) {
                        if ($record->file_path && Storage::disk('public')->exists($record->file_path)) {
                            Storage::disk('public')->delete($record->file_path);
                        }
                        $record->encryptionKey()->delete();

                        try {
                            $this->ownerRecord->audits()->create([
                                'user_id' => Auth::id(),
                                'event' => 'file_deleted',
                                'auditable_type' => Incident::class,
                                'auditable_id' => $this->ownerRecord->id,
                                'old_values' => [
                                    'filename' => (string) Str::of($record->original_filename)->ascii(),
                                ],
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Error creating audit log: '.$e->getMessage());
                        }
                    }),
            ]);
    }
}
