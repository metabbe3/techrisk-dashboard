<?php

namespace App\Filament\Filters;

use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class ContentSearchFilter
{
    public static function make(): Filter
    {
        return Filter::make('content_search')
            ->label('Content Search')
            ->form([
                TextInput::make('query')
                    ->label('Search in summary, root cause, timeline, remark')
                    ->placeholder('e.g., payment gateway timeout'),
            ])
            ->query(function (Builder $query, array $data) {
                $term = $data['query'] ?? null;
                if (blank($term)) {
                    return $query;
                }
                $like = '%'.$term.'%';

                return $query->where(function (Builder $q) use ($like) {
                    $q->where('title', 'like', $like)
                        ->orWhere('summary', 'like', $like)
                        ->orWhere('root_cause', 'like', $like)
                        ->orWhere('timeline', 'like', $like)
                        ->orWhere('remark', 'like', $like)
                        ->orWhere('improvements', 'like', $like);
                });
            });
    }
}
