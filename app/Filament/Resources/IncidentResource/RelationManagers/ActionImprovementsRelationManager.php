<?php

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use App\Models\User;
use App\Notifications\ActionImprovementReminder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ActionImprovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'actionImprovements';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('detail')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\DatePicker::make('due_date')
                    ->required(),
                Forms\Components\TagsInput::make('pic_email')
                    ->required(),
                Forms\Components\Toggle::make('reminder'),
                Forms\Components\Select::make('reminder_frequency')
                    ->options([
                        'weekly' => 'Once a week',
                        'biweekly' => 'Once every 2 weeks',
                    ]),
                Forms\Components\Select::make('status')
                    ->options([
                        'draft' => 'Draft (AI-suggested)',
                        'pending' => 'Pending',
                        'done' => 'Done',
                    ])
                    ->default('pending')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title'),
                Tables\Columns\TextColumn::make('due_date')
                    ->date(),
                Tables\Columns\TextColumn::make('pic_email'),
                Tables\Columns\IconColumn::make('reminder')
                    ->boolean(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'pending' => 'warning',
                        'done' => 'success',
                        default => 'secondary',
                    }),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('send_reminder')
                    ->label('Email')
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('Send a reminder email to the assigned PIC email(s) via Netcore.')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->action(function ($record) {
                        $sent = [];
                        foreach (($record->pic_email ?? []) as $email) {
                            $user = User::where('email', $email)->first();
                            if ($user && ! in_array($user->id, $sent)) {
                                $user->notify(new ActionImprovementReminder($record));
                                $sent[] = $user->id;
                            }
                        }

                        Notification::make()
                            ->when(count($sent), fn ($n) => $n->success()->title('Reminder queued')->body('Email queued for '.count($sent).' recipient(s).'))
                            ->when(! count($sent), fn ($n) => $n->warning()->title('No matching users')->body('No registered users matched the PIC email(s).'))
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
