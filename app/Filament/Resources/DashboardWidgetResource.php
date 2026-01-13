<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DashboardWidgetResource\Pages;
use App\Models\DashboardWidget;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TagsInput;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

class DashboardWidgetResource extends Resource
{
    protected static ?string $model = DashboardWidget::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog';

    protected static ?string $navigationGroup = 'Settings';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('view dashboard widgets');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('class_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('icon'),
                Select::make('type')
                    ->options([
                        'stat' => 'Statistic',
                        'chart' => 'Chart',
                        'table' => 'Table',
                    ])
                    ->required()
                    ->reactive(),
                Select::make('chart_type')
                    ->options([
                        'bar' => 'Bar',
                        'line' => 'Line',
                        'pie' => 'Pie',
                    ])
                    ->visible(fn ($get) => $get('type') === 'chart'),
                Textarea::make('query')
                    ->required()
                    ->columnSpanFull()
                    ->rules([
                        function () {
                            return function (string $attribute, $value, callable $fail) {
                                if (empty($value)) {
                                    return;
                                }

                                $query = strtolower(trim($value));

                                // Must start with SELECT
                                if (!str_starts_with($query, 'select')) {
                                    $fail('The :attribute must be a SELECT query.');
                                    return;
                                }

                                // Block dangerous keywords
                                $dangerousKeywords = [
                                    'drop ', 'delete ', 'insert ', 'update ',
                                    'truncate ', 'alter ', 'create ', 'exec ',
                                    'execute ', 'script ', 'javascript:',
                                    '--', ';--', '/*', '*/', 'xp_', 'sp_'
                                ];

                                foreach ($dangerousKeywords as $keyword) {
                                    if (str_contains($query, $keyword)) {
                                        $fail('The :attribute contains dangerous keywords.');
                                        return;
                                    }
                                }

                                // Must be a SELECT query with aggregate function (for stat widget)
                                if (!str_contains($query, 'count') &&
                                    !str_contains($query, 'sum') &&
                                    !str_contains($query, 'avg') &&
                                    !str_contains($query, 'min') &&
                                    !str_contains($query, 'max')) {
                                    $fail('The :attribute must use an aggregate function (COUNT, SUM, AVG, MIN, MAX).');
                                    return;
                                }

                                // Result column should be named 'value' for the widget to work
                                if (!str_contains($query, ' as value') &&
                                    !str_contains($query, ' as `value`')) {
                                    $fail('The query result must be aliased as "value" (e.g., SELECT COUNT(*) as value FROM incidents).');
                                    return;
                                }
                            };
                        },
                    ]),
                TagsInput::make('columns')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('type'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListDashboardWidgets::route('/'),
            'create' => Pages\CreateDashboardWidget::route('/create'),
            'edit' => Pages\EditDashboardWidget::route('/{record}/edit'),
        ];
    }
}
