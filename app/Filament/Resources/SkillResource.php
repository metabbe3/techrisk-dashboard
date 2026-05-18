<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SkillResource\Pages;
use App\Models\Skill;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SkillResource extends Resource
{
    protected static ?string $model = Skill::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Skills Library';

    protected static ?string $modelLabel = 'Skill';

    protected static ?string $pluralModelLabel = 'Skills Library';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationGroup = 'Settings';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->can('manage incidents');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identity')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true)
                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                            ->helperText('Unique slug identifier (kebab-case, e.g. threat-modeling).'),
                        Forms\Components\TextInput::make('display_name')
                            ->required()
                            ->maxLength(150)
                            ->columnSpan(2),
                        Forms\Components\Select::make('domain')
                            ->options([
                                'ai-security' => 'AI Security',
                                'appsec' => 'Application Security',
                                'cloud' => 'Cloud Security',
                                'compliance' => 'Compliance',
                                'devsecops' => 'DevSecOps',
                                'identity' => 'Identity & Access',
                                'incident-response' => 'Incident Response',
                                'network' => 'Network Security',
                                'secops' => 'Security Operations',
                                'vuln-management' => 'Vulnerability Management',
                                'role' => 'Role Bundle',
                                'custom' => 'Custom',
                            ])
                            ->searchable()
                            ->columnSpan(2),
                    ])->columns(4),

                Forms\Components\Section::make('Description')
                    ->schema([
                        Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Short description for the skills list and agent assignment UI.'),
                    ]),

                Forms\Components\Section::make('Content')
                    ->schema([
                        Forms\Components\Textarea::make('content')
                            ->rows(20)
                            ->columnSpanFull()
                            ->helperText('Full skill content in Markdown. Includes frameworks, procedures, checklists, and methodology. This content is injected into agent prompts when the skill is assigned.')
                            ->hint('Markdown supported'),
                    ]),

                Forms\Components\Section::make('Metadata')
                    ->schema([
                        Forms\Components\TagsInput::make('frameworks')
                            ->placeholder('Add framework (e.g. OWASP ASVS)')
                            ->splitKeys([',', 'Tab'])
                            ->columnSpan(2),
                        Forms\Components\TagsInput::make('tags')
                            ->placeholder('Add tag')
                            ->splitKeys([',', 'Tab'])
                            ->columnSpan(2),
                        Forms\Components\Select::make('difficulty')
                            ->options([
                                'beginner' => 'Beginner',
                                'intermediate' => 'Intermediate',
                                'advanced' => 'Advanced',
                            ]),
                        Forms\Components\TextInput::make('source')
                            ->maxLength(50)
                            ->placeholder('unitoneai, custom'),
                        Forms\Components\TextInput::make('source_id')
                            ->maxLength(100)
                            ->placeholder('Original ID from upstream source'),
                        Forms\Components\TextInput::make('version')
                            ->maxLength(20)
                            ->placeholder('1.0.0'),
                    ])->columns(4),

                Forms\Components\Section::make('Status')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->helperText('Inactive skills are hidden from agent assignment.'),
                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('domain')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ai-security' => 'purple',
                        'appsec' => 'blue',
                        'cloud' => 'sky',
                        'compliance' => 'amber',
                        'devsecops' => 'orange',
                        'identity' => 'indigo',
                        'incident-response' => 'red',
                        'network' => 'teal',
                        'secops' => 'cyan',
                        'vuln-management' => 'rose',
                        'role' => 'fuchsia',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('difficulty')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'beginner' => 'success',
                        'intermediate' => 'warning',
                        'advanced' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('frameworks')
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->searchable(),
                Tables\Columns\TextColumn::make('agents_count')
                    ->counts('agents')
                    ->label('Agents')
                    ->badge()
                    ->color('info'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
            ])
            ->defaultSort('domain')
            ->groups([
                Tables\Grouping\Group::make('domain')
                    ->label('Domain'),
                Tables\Grouping\Group::make('difficulty')
                    ->label('Difficulty'),
                Tables\Grouping\Group::make('source')
                    ->label('Source'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('domain')
                    ->options([
                        'ai-security' => 'AI Security',
                        'appsec' => 'Application Security',
                        'cloud' => 'Cloud Security',
                        'compliance' => 'Compliance',
                        'devsecops' => 'DevSecOps',
                        'identity' => 'Identity & Access',
                        'incident-response' => 'Incident Response',
                        'network' => 'Network Security',
                        'secops' => 'Security Operations',
                        'vuln-management' => 'Vulnerability Management',
                        'role' => 'Role Bundle',
                        'custom' => 'Custom',
                    ]),
                Tables\Filters\SelectFilter::make('difficulty')
                    ->options([
                        'beginner' => 'Beginner',
                        'intermediate' => 'Intermediate',
                        'advanced' => 'Advanced',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\SelectFilter::make('source')
                    ->options(fn () => Skill::query()->whereNotNull('source')->distinct()->pluck('source', 'source')->toArray()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activate')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->action(fn ($records) => $records->each->update(['is_active' => true])),
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Deactivate')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->action(fn ($records) => $records->each->update(['is_active' => false])),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSkills::route('/'),
            'create' => Pages\CreateSkill::route('/create'),
            'edit' => Pages\EditSkill::route('/{record}/edit'),
        ];
    }
}
