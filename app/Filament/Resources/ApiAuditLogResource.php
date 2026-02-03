<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ApiAuditLogResource\Pages\ListApiAuditLogs;
use App\Models\ApiAuditLog;
use App\Models\UserAuditLogSetting;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ApiAuditLogResource extends Resource
{
    protected static ?string $model = ApiAuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 100;

    public static function canViewAny(): bool
    {
        return auth()->user()->can('view audit logs');
    }

    public static function canCreate(): bool
    {
        return false; // Audit logs are read-only
    }

    public static function canEdit(Model $record): bool
    {
        return false; // Audit logs are read-only
    }

    public static function canDelete(Model $record): bool
    {
        return false; // Audit logs are read-only
    }

    public static function canDeleteAny(): bool
    {
        return false; // Audit logs are read-only
    }

    public static function table(Table $table): Table
    {
        // Get current user's audit log settings
        $user = auth()->user();
        $settings = UserAuditLogSetting::forUser($user);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => self::applyAccessControl($query, $settings))
            ->defaultSort('request_timestamp', 'desc')
            ->columns([
                TextColumn::make('request_timestamp')
                    ->label('Timestamp')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->description(fn (ApiAuditLog $record): string => $record->response_time_ms . 'ms'),

                TextColumn::make('method')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'GET' => 'success',
                        'POST' => 'primary',
                        'PUT', 'PATCH' => 'warning',
                        'DELETE' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('endpoint')
                    ->searchable()
                    ->limit(40)
                    ->wrap()
                    ->tooltip(fn (ApiAuditLog $record): string => $record->endpoint),

                TextColumn::make('user_email')
                    ->label('User')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('response_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 200 && $state < 300 => 'success',
                        $state >= 300 && $state < 400 => 'info',
                        $state >= 400 && $state < 500 => 'warning',
                        $state >= 500 => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('ip_address')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('environment')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'production' => 'danger',
                        'staging' => 'warning',
                        'local' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('error_message')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => auth()->user()->hasRole('admin')),
            ])
            ->filters([
                // Year filter (only show years user has access to)
                SelectFilter::make('year')
                    ->label('Year')
                    ->options(fn () => self::getAvailableYears($settings))
                    ->query(fn (Builder $query, array $data) =>
                        isset($data['value'])
                            ? $query->whereYear('request_timestamp', $data['value'])
                            : $query
                    ),

                // Method filter
                SelectFilter::make('method')
                    ->options([
                        'GET' => 'GET',
                        'POST' => 'POST',
                        'PUT' => 'PUT',
                        'PATCH' => 'PATCH',
                        'DELETE' => 'DELETE',
                    ]),

                // Status filter
                SelectFilter::make('response_status')
                    ->label('Status')
                    ->options([
                        '2xx' => '2xx - Success',
                        '3xx' => '3xx - Redirect',
                        '4xx' => '4xx - Client Error',
                        '5xx' => '5xx - Server Error',
                    ])
                    ->query(fn (Builder $query, array $data) =>
                        empty($data['value']) ? $query : match ($data['value']) {
                            '2xx' => $query->whereBetween('response_status', [200, 299]),
                            '3xx' => $query->whereBetween('response_status', [300, 399]),
                            '4xx' => $query->whereBetween('response_status', [400, 499]),
                            '5xx' => $query->where('response_status', '>=', 500),
                            default => $query,
                        }
                    ),

                // Environment filter (admin only)
                SelectFilter::make('environment')
                    ->options([
                        'production' => 'Production',
                        'staging' => 'Staging',
                        'local' => 'Local',
                    ])
                    ->visible(fn () => auth()->user()->hasRole('admin')),

                // Date range filter
                Filter::make('date_range')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label('From Date')
                            ->maxDate(fn () => now()),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label('To Date')
                            ->maxDate(fn () => now()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder =>
                                    $query->whereDate('request_timestamp', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder =>
                                    $query->whereDate('request_timestamp', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators['from'] = 'From ' . $data['from'];
                        }
                        if ($data['until'] ?? null) {
                            $indicators['until'] = 'Until ' . $data['until'];
                        }

                        return $indicators;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->form(fn (ApiAuditLog $record): array => [
                        \Filament\Forms\Components\Section::make('Request Details')
                            ->schema([
                                \Filament\Forms\Components\TextEntry::make('request_timestamp')
                                    ->dateTime(),
                                \Filament\Forms\Components\TextEntry::make('method'),
                                \Filament\Forms\Components\TextEntry::make('endpoint'),
                                \Filament\Forms\Components\TextEntry::make('user_email')
                                    ->label('User'),
                                \Filament\Forms\Components\TextEntry::make('ip_address'),
                                \Filament\Forms\Components\TextEntry::make('user_agent')
                                    ->limit(50),
                            ])->columns(2),

                        \Filament\Forms\Components\Section::make('Response Details')
                            ->schema([
                                \Filament\Forms\Components\TextEntry::make('response_timestamp')
                                    ->dateTime(),
                                \Filament\Forms\Components\TextEntry::make('response_status')
                                    ->badge(),
                                \Filament\Forms\Components\TextEntry::make('response_time_ms')
                                    ->label('Response Time (ms)'),
                                \Filament\Forms\Components\TextEntry::make('response_size_bytes')
                                    ->label('Response Size (bytes)'),
                            ])->columns(2),

                        \Filament\Forms\Components\Section::make('Request Data')
                            ->schema([
                                \Filament\Forms\Components\KeyValueEntry::make('query_params')
                                    ->label('Query Parameters')
                                    ->visible(fn () => $record->query_params !== null),
                                \Filament\Forms\Components\KeyValueEntry::make('request_body')
                                    ->label('Request Body')
                                    ->visible(fn () => $record->request_body !== null),
                            ]),

                        \Filament\Forms\Components\Section::make('Response Data')
                            ->schema([
                                \Filament\Forms\Components\TextareaEntry::make('response_data')
                                    ->label('Response')
                                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state)
                                    ->rows(10)
                                    ->visible(fn () => auth()->user()->hasRole('admin') && $record->response_data !== null),
                            ])->collapsible(),

                        \Filament\Forms\Components\Section::make('Error')
                            ->schema([
                                \Filament\Forms\Components\TextEntry::make('error_message')
                                    ->markdown()
                                    ->visible(fn () => $record->error_message !== null),
                            ])->visible(fn () => $record->error_message !== null),
                    ]),
            ])
            ->bulkActions([
                // No bulk actions - audit logs are read-only
            ])
            ->emptyStateHeading('No audit logs found')
            ->emptyStateDescription(fn () => auth()->user()->hasRole('admin')
                ? 'No API activity has been recorded yet.'
                : 'You have no API activity recorded yet, or it may be outside your configured year range.'
            )
            ->paginated([25, 50, 100]);
    }

    /**
     * Apply access control based on user role and year permissions
     */
    protected static function applyAccessControl(Builder $query, UserAuditLogSetting $settings): Builder
    {
        $user = auth()->user();
        $isAdmin = $user->hasRole('admin');

        // Non-admins can only see their own audit logs
        if (! $isAdmin) {
            $query->where('user_id', $user->id);
        }

        // Apply year filtering if user doesn't have full access
        if (! $settings->can_view_all_logs && ! empty($settings->allowed_years)) {
            $query->whereYear('request_timestamp', $settings->allowed_years);
        }

        return $query;
    }

    /**
     * Get available years based on user's settings
     */
    protected static function getAvailableYears(UserAuditLogSetting $settings): array
    {
        if ($settings->can_view_all_logs || auth()->user()->hasRole('admin')) {
            // Get all years that have audit logs
            $years = ApiAuditLog::selectRaw('DISTINCT YEAR(request_timestamp) as year')
                ->orderBy('year', 'desc')
                ->pluck('year', 'year')
                ->toArray();

            return $years ?: [(int) date('Y') => (string) date('Y')];
        }

        // Return user's allowed years
        $allowedYears = $settings->allowed_years ?? [];
        $yearsArray = [];
        foreach ($allowedYears as $year) {
            $yearsArray[(string) $year] = (string) $year;
        }

        return $yearsArray ?: [(int) date('Y') => (string) date('Y')];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiAuditLogs::route('/'),
        ];
    }
}
