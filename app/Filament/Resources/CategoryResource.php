<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('type')
                    ->label('Category Type')
                    ->options([
                        Category::TYPE_BUSINESS_CATEGORY => 'Business Category',
                        Category::TYPE_ROOT_CAUSE_CATEGORY => 'Root Cause Category',
                        Category::TYPE_RESPONSIBLE_TEAM => 'Responsible Team',
                    ])
                    ->required()
                    ->live()
                    ->native(false),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true, modifyRuleUsing: function ($rule, $get) {
                        $rule->where('type', $get('type'));
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Category::TYPE_BUSINESS_CATEGORY => 'primary',
                        Category::TYPE_ROOT_CAUSE_CATEGORY => 'warning',
                        Category::TYPE_RESPONSIBLE_TEAM => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Category::TYPE_BUSINESS_CATEGORY => 'Business Category',
                        Category::TYPE_ROOT_CAUSE_CATEGORY => 'Root Cause Category',
                        Category::TYPE_RESPONSIBLE_TEAM => 'Responsible Team',
                        default => $state,
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('type')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Category Type')
                    ->options([
                        Category::TYPE_BUSINESS_CATEGORY => 'Business Category',
                        Category::TYPE_ROOT_CAUSE_CATEGORY => 'Root Cause Category',
                        Category::TYPE_RESPONSIBLE_TEAM => 'Responsible Team',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->databaseTransaction(),
                Tables\Actions\DeleteAction::make()->databaseTransaction(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->databaseTransaction(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
