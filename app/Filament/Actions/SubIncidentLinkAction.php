<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Shared link-sub-incident plumbing (PROJ-022): the picker query + options,
 * pick validation, and the submit handler behind the list page's row action
 * and the view page's header action.
 */
class SubIncidentLinkAction
{
    /**
     * Standalone Incidents only — never supers, never already-linked subs,
     * never Issues. Access scope still applies; cross-year allowed.
     */
    public static function pickerQuery(?Incident $record): Builder
    {
        return Incident::query()
            ->where('classification', IncidentClassification::Incident->value)
            ->whereNull('parent_incident_id')
            ->whereDoesntHave('subIncidents')
            ->when($record, fn (Builder $query) => $query->whereNot('id', $record->getKey()))
            ->applyUserYearAccess(Auth::user())
            ->orderBy('no');
    }

    /** @return array<int, string> id => "NO-xxx — Title" for the modal Select */
    public static function options(?Incident $record): array
    {
        return self::pickerQuery($record)
            ->get(['id', 'no', 'title'])
            ->mapWithKeys(fn (Incident $incident) => [$incident->getKey() => "{$incident->no} — {$incident->title}"])
            ->all();
    }

    /**
     * Form rule: reject anything the picker would exclude — a super, an
     * already-linked sub, an Issue — re-checked at submit against races
     * between option render and submit.
     */
    public static function validatePick(mixed $value, Closure $fail): void
    {
        $candidate = Incident::find((int) $value);

        if ($candidate === null
            || $candidate->classification !== IncidentClassification::Incident
            || $candidate->hasIncidentLinks()) {
            $fail('That incident is already linked in a hierarchy.');
        }
    }

    /** MOVE semantics: the single parent column is simply replaced. */
    public static function link(Incident $super, int $subId): void
    {
        $sub = Incident::findOrFail($subId);

        if ($sub->hasIncidentLinks()) {
            throw ValidationException::withMessages([
                'sub_incident_id' => 'That incident is already linked in a hierarchy.',
            ]);
        }

        $sub->update(['parent_incident_id' => $super->getKey()]);
    }
}
