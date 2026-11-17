<?php

declare(strict_types=1);

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
        $storeDocument = app(\App\Actions\StoreInvestigationDocument::class);
        $models = $aiService->getModelsForPicker();
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
                    ->tooltip(fn ($record) => $record->ai_summary ? 'Summarized by '.$record->ai_summary_model : 'Not summarized'),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->action(function (array $data) use ($storeDocument): void {
                        $fileInput = $data['file_path'] ?? null;

                        if (is_array($fileInput)) {
                            $fileInput = reset($fileInput);
                        }

                        try {
                            if ($fileInput instanceof UploadedFile) {
                                $storeDocument->handle(
                                    $this->ownerRecord,
                                    $fileInput->get(),
                                    $fileInput->getClientOriginalName(),
                                    $data['description'],
                                );

                                Notification::make()
                                    ->title('Document uploaded successfully')
                                    ->body('Document queued for Markdown conversion.')
                                    ->success()
                                    ->send();
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
                                    ->body('Document summarized using '.$result->model)
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
                    ->modalHeading(fn ($record) => 'AI Summary — '.$record->original_filename)
                    ->modalDescription(fn ($record) => 'Model: '.$record->ai_summary_model.' | Summarized: '.($record->ai_summary_at?->diffForHumans() ?? 'N/A'))
                    ->modalContent(fn ($record) => new \Illuminate\Support\HtmlString(
                        '<div class="prose prose-sm max-w-none dark:prose-invert" style="max-height:500px;overflow-y:auto;">'
                        .\Illuminate\Support\Str::markdown($record->ai_summary)
                        .'</div>'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Tables\Actions\Action::make('view_markdown')
                    ->icon('heroicon-o-code-bracket')
                    ->label('View MD')
                    ->color('gray')
                    ->visible(fn ($record): bool => $record->markdown_path !== null)
                    ->modalHeading(fn ($record) => 'Markdown — '.$record->original_filename)
                    ->modalContent(fn ($record) => new \Illuminate\Support\HtmlString('<pre style="white-space:pre-wrap;font-size:12px;max-height:500px;overflow-y:auto;background:#f8fafc;padding:16px;border-radius:8px;">'.e(Storage::disk('local')->get($record->markdown_path)).'</pre>'))
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
                    ->modalDescription(fn ($record) => 'Convert "'.$record->original_filename.'" to Markdown? This may take a moment for large files.')
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
                // PROJ-016: quick-add — parse this document's ACTION
                // IMPROVEMENT section into draft Action Improvements on the
                // incident. Iteration 2: an AI model can be chosen as the
                // extractor instead of the deterministic fast parse (the
                // regex trim mangled some items) — the model reads the whole
                // document and maps title/detail/priority/due date/owners.
                Tables\Actions\Action::make('add_action_items')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->label('Add Action Items')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Add Action Items from RCA')
                    ->modalDescription(fn ($record) => 'Add the action improvement items of "'.$record->original_filename.'" as drafts on this incident. An AI model reads the document and maps the register columns (matched owners are auto-assigned); Fast parse is the deterministic regex. Existing titles are skipped.')
                    ->form(function () use ($aiService, $models, $defaultModel) {
                        // Gated on availability, not on $models — the picker
                        // list comes from config even when no gateway is
                        // configured (tests), which would silently flip the
                        // default onto a failing AI path.
                        $formModels = $aiService->isAvailable() ? $models : [];

                        if ($formModels === []) {
                            return []; // no AI configured — fast-parse only
                        }

                        return [
                            \Filament\Forms\Components\Select::make('extractor')
                                ->label('Extractor')
                                ->options(['parser' => 'Fast parse (no AI)'] + $formModels)
                                ->default(isset($formModels[$defaultModel]) ? $defaultModel : 'parser')
                                ->required(),
                        ];
                    })
                    ->visible(fn ($record): bool => $record->markdown_conversion_status === 'completed'
                        && $record->markdown_path !== null
                        && (bool) auth()->user()?->can('manage incidents'))
                    ->action(function ($record, array $data) {
                        $markdown = $record->getMarkdownContent();

                        if (blank($markdown)) {
                            Notification::make()
                                ->title('No markdown')
                                ->body('No converted markdown available for this document.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $service = app(\App\Services\ActionImprovements\DocumentActionItemService::class);
                        $extractor = $data['extractor'] ?? 'parser';

                        if ($extractor === 'parser') {
                            $items = array_map(
                                fn (string $title): array => ['title' => $title],
                                $service->extractFromMarkdown($markdown),
                            );
                            $provenance = '_Drafted from RCA document: '.$record->original_filename.' — review and promote._';
                            $emptyBody = 'No ACTION IMPROVEMENT section (or no usable items) in the converted markdown.';
                        } else {
                            $items = $service->extractWithAi($markdown, $extractor);
                            $provenance = '_Drafted by AI from RCA document: '.$record->original_filename.' — review and promote._';
                            $emptyBody = 'The model returned no items — it may have found none or failed. Try another model or Fast parse.';
                        }

                        if ($items === []) {
                            Notification::make()
                                ->title('No action items found')
                                ->body($emptyBody)
                                ->warning()
                                ->send();

                            return;
                        }

                        $created = $service->createDrafts($this->getOwnerRecord(), $items, $provenance);

                        $created > 0
                            ? Notification::make()
                                ->title($created.' draft action item(s) added')
                                ->body('Review and promote them in the Action Improvements tab.')
                                ->success()
                                ->send()
                            : Notification::make()
                                ->title('Nothing new to add')
                                ->body(count($items).' item(s) found — all already exist on this incident.')
                                ->info()
                                ->send();
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

                                // Invalidate the old conversion — otherwise the
                                // row keeps serving the previous file's markdown
                                // forever (zip/corpus are cached-first on it).
                                $data['markdown_path'] = null;
                                $data['markdown_conversion_status'] = 'pending';
                                $data['markdown_converted_at'] = null;

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

                            // Re-convert the replaced file (CreateAction precedent)
                            \App\Jobs\ConvertDocumentToMarkdown::dispatch($record);

                            $this->encryptionData = [];
                            Notification::make()
                                ->title('Document updated successfully')
                                ->body('Document queued for Markdown conversion.')
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
