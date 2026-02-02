<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportTemplateResource\Pages;
use App\Models\ReportTemplate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReportTemplateResource extends Resource
{
    protected static ?string $model = ReportTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-bookmark';

    protected static ?string $navigationGroup = 'Settings';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Textarea::make('filters')
                    ->required()
                    ->rows(3)
                    ->helperText('Enter filters as JSON'),
                Textarea::make('columns')
                    ->required()
                    ->rows(3)
                    ->helperText('Enter columns as JSON'),
                Textarea::make('metrics')
                    ->required()
                    ->rows(3)
                    ->helperText('Enter metrics as JSON'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->databaseTransaction(),
                Tables\Actions\DeleteAction::make()->databaseTransaction(),
                Action::make('schedule')
                    ->databaseTransaction()
                    ->form([
                        TextInput::make('email')->email()->required(),
                        Select::make('schedule')
                            ->options([
                                'daily' => 'Daily',
                                'weekly' => 'Weekly',
                                'monthly' => 'Monthly',
                            ])
                            ->required(),
                    ])
                    ->action(function (array $data, ReportTemplate $record): void {
                        $record->update([
                            'email' => $data['email'],
                            'schedule' => $data['schedule'],
                        ]);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->databaseTransaction(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReportTemplates::route('/'),
            'create' => Pages\CreateReportTemplate::route('/create'),
            'edit' => Pages\EditReportTemplate::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
    }
}
