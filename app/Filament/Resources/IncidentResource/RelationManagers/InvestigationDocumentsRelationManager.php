<?php

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use App\Models\Incident;
use App\Models\InvestigationDocument;
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
        $aiService = app(\App\Services\Ai\AiTextService::class);
        $models = $aiService->getAvailableModels();
        $defaultModel = \App\Models\AiSetting::get('default_model', config('ai.default_model', 'SMART-MODEL'));

        return $table
            ->recordTitleAttribute('description')
            ->columns([
                Tables\Columns\TextColumn::make('description'),
                Tables\Columns\TextColumn::make('original_filename')
                    ->label('Document'),
                Tables\Columns\TextColumn::make('markdown_conversion_status')
                    ->label('MD Status')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        'completed' => 'success',
                        'processing' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('ai_summary')
                    ->label('AI Summary')
                    ->boolean()
                    ->trueIcon('heroicon-o-sparkles')
                    ->falseIcon('heroicon-o-sparkles')
                    ->trueColor('primary')
                    ->falseColor('gray')
                    ->tooltip(fn ($record) => $record->ai_summary ? 'Summarized by ' . $record->ai_summary_model : 'Not summarized'),
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
                Tables\Actions\Action::make('ai_summarize')
                    ->icon('heroicon-o-sparkles')
                    ->label('AI Summarize')
                    ->color('primary')
                    ->visible(fn () => $aiService->isAvailable())
                    ->form(function () use ($models) {
                        if (count($models) <= 1) {
                            return [];
                        }

                        return [
                            \Filament\Forms\Components\Select::make('model')
                                ->label('AI Model')
                                ->options($models)
                                ->default(array_key_first($models))
                                ->required(),
                        ];
                    })
                    ->action(function ($record, array $data, \Livewire\Component $livewire) use ($aiService, $defaultModel) {
                        try {
                            $document = InvestigationDocument::with('encryptionKey')->find($record->id);
                            if (! $document) {
                                Notification::make()->title('Document not found')->danger()->send();
                                return;
                            }

                            $markdown = $document->getMarkdownContent();

                            // Try converting first if no markdown exists
                            if (blank($markdown) && $document->markdown_conversion_status !== 'completed') {
                                try {
                                    $converter = app(\App\Services\Markdown\DocumentConverterService::class);
                                    $converter->convert($document);
                                    $document->refresh();
                                    $markdown = $document->getMarkdownContent();
                                } catch (\Exception $e) {
                                    Notification::make()
                                        ->title('Conversion Failed')
                                        ->body($e->getMessage())
                                        ->danger()
                                        ->send();
                                    return;
                                }
                            }

                            if (blank($markdown)) {
                                Notification::make()
                                    ->title('No Content')
                                    ->body('Could not extract text from this document. It may be image-based or unsupported.')
                                    ->warning()
                                    ->send();
                                return;
                            }

                            $result = $aiService->summarizeDocument(
                                content: $markdown,
                                originalFilename: $document->original_filename,
                                model: $data['model'] ?? $defaultModel,
                            );

                            if ($result->success) {
                                $document->update([
                                    'ai_summary' => $result->text,
                                    'ai_summary_model' => $result->model,
                                    'ai_summary_at' => now(),
                                ]);

                                Notification::make()
                                    ->title('AI Summary Complete')
                                    ->body('Document summarized using ' . $result->model)
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('AI Summary Failed')
                                    ->body($result->error)
                                    ->danger()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('AI Summary Error')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }

                        $livewire->js('setTimeout(() => $wire.$refresh(), 200)');
                    }),
                Tables\Actions\Action::make('view_summary')
                    ->icon('heroicon-o-document-text')
                    ->label('View Summary')
                    ->color('info')
                    ->visible(fn ($record): bool => $record->ai_summary !== null)
                    ->modalHeading(fn ($record) => 'AI Summary — ' . $record->original_filename)
                    ->modalDescription(fn ($record) => 'Model: ' . $record->ai_summary_model . ' | Summarized: ' . ($record->ai_summary_at?->diffForHumans() ?? 'N/A'))
                    ->modalContent(fn ($record) => new \Illuminate\Support\HtmlString(
                        '<div class="prose prose-sm max-w-none dark:prose-invert" style="max-height:500px;overflow-y:auto;">'
                        . \Illuminate\Support\Str::markdown($record->ai_summary)
                        . '</div>'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Tables\Actions\Action::make('view_markdown')
                    ->icon('heroicon-o-code-bracket')
                    ->label('View MD')
                    ->color('gray')
                    ->visible(fn ($record): bool => $record->markdown_path !== null)
                    ->modalHeading(fn ($record) => 'Markdown — ' . $record->original_filename)
                    ->modalContent(fn ($record) => new \Illuminate\Support\HtmlString('<pre style="white-space:pre-wrap;font-size:12px;max-height:500px;overflow-y:auto;background:#f8fafc;padding:16px;border-radius:8px;">' . e(Storage::disk('local')->get($record->markdown_path)) . '</pre>'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
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
                    ->label('Convert to MD')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Convert to Markdown')
                    ->modalDescription(fn ($record) => 'Convert "' . $record->original_filename . '" to Markdown? This may take a moment for large files.')
                    ->modalSubmitActionLabel('Start Conversion')
                    ->action(function ($record, \Livewire\Component $livewire) {
                        try {
                            $converter = app(\App\Services\Markdown\DocumentConverterService::class);
                            $result = $converter->convert($record);
                            $record->refresh();

                            if ($result) {
                                $chars = number_format(strlen($result));
                                $words = number_format(str_word_count($result));
                                Notification::make()
                                    ->title('Conversion Complete')
                                    ->body("Extracted {$chars} characters ({$words} words) from {$record->original_filename}.")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Conversion Skipped')
                                    ->body('This file type is not supported for conversion (only PDF, DOCX).')
                                    ->warning()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            $record->refresh();
                            Notification::make()
                                ->title('Conversion Failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }

                        $livewire->js('setTimeout(() => $wire.$refresh(), 200)');
                    }),
                Tables\Actions\Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->label('Download')
                    ->action(fn ($record) => redirect()->route('documents.download', $record)),
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
