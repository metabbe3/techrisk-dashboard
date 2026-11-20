<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\IncidentStatus;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The ONE definition of "incidents this user handles" (owner 2026-11-20):
 * PIC on the Source & Assignment tab (the `pics()` relation) — NOT the
 * action-improvement pic_email. Scope is deliberately WIDER than the
 * portal counting rules: any classification (Issue included), any severity
 * (G / Non Incident included), sub-incidents included — "everything I
 * handle". Feeds the profile-menu count, the My Incident deep link and the
 * profile-page contribution graph.
 */
class MyIncidents
{
    /** Every incident where the user is a PIC. */
    public static function query(User $user): Builder
    {
        return Incident::query()
            ->whereHas('pics', fn (Builder $q) => $q->where('users.id', $user->id));
    }

    /**
     * Not fully done = still running (any non-Completed status) OR Completed
     * but action improvements are still open. THE count definition for the
     * profile menu ("Your active incidents").
     */
    public static function notFullyDone(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('incident_status', '!=', IncidentStatus::Completed->value)
                ->orWhere(function (Builder $q2) {
                    $q2->where('incident_status', IncidentStatus::Completed->value)
                        ->whereHas('actionImprovements', fn (Builder $ai) => $ai->whereIn('status', ActionImprovement::openStatuses()));
                });
        });
    }

    /** Cached count for the dropdown item (renders on every page load). */
    public static function activeCount(User $user): int
    {
        $key = 'tr_profile_active_count_'.md5((string) json_encode([
            'user' => $user->id,
            'v' => Cache::get('dashboard_cache_version', 0),
        ]));

        return (int) Cache::remember($key, now()->addMinutes(5), fn () => self::notFullyDone(self::query($user))->count());
    }

    /**
     * GitHub-style contribution data: a rolling 12-month window ending today,
     * Monday-first weeks as columns. Each DAY gets ONE tier — the
     * highest-priority status among that date's incidents (1 running >
     * 2 monitoring > 3 done; owner: "warna sesuai prioritas status yang
     * paling tinggi"). Tier 0 = in-window day with no incidents.
     *
     * @return array{weeks: list<array{month: string|null, days: list<array{date: string, tier: int, count: int, tooltip: string}|null>}>}
     */
    public static function contributionGraph(User $user): array
    {
        $end = now()->endOfDay();
        $start = now()->subMonths(12)->startOfWeek(Carbon::MONDAY);

        $incidents = self::query($user)
            ->whereBetween('incident_date', [$start, $end])
            ->withExists(['actionImprovements as has_open_ai' => fn (Builder $ai) => $ai->whereIn('status', ActionImprovement::openStatuses())])
            ->get(['id', 'incident_date', 'incident_status']);

        $byDay = [];
        foreach ($incidents as $incident) {
            $day = $incident->incident_date->format('Y-m-d');
            $tier = $incident->isNotDone()
                ? 1
                : ($incident->has_open_ai ? 2 : 3);
            // Higher priority = LOWER number: keep the day's minimum.
            $byDay[$day]['tier'] = min($byDay[$day]['tier'] ?? 99, $tier);
            $byDay[$day]['count'] = ($byDay[$day]['count'] ?? 0) + 1;
        }

        $weeks = [];
        $week = null;
        for ($cursor = $start->copy(); $cursor <= $end; $cursor->addDay()) {
            $key = $cursor->format('Y-m-d');
            $tier = $byDay[$key]['tier'] ?? 0;
            $count = $byDay[$key]['count'] ?? 0;
            $label = $cursor->isoFormat('MMM D, YYYY');
            $day = [
                'date' => $key,
                'tier' => $tier,
                'count' => $count,
                'tooltip' => $count === 0
                    ? "No incidents on {$label}"
                    : trans_choice(':1 incident on :2 — :3|:1 incidents on :2 — :3', $count, [
                        '1' => $count,
                        '2' => $label,
                        '3' => match ($tier) {
                            1 => 'In progress',
                            2 => 'Monitoring actions',
                            default => 'Completed',
                        },
                    ]),
            ];

            $week ??= ['month' => null, 'days' => []];
            // The month label sits on the week containing the 1st (the very
            // first column is skipped — a clipped label, GitHub's own look).
            if ($cursor->day === 1 && $week['days'] !== []) {
                $week['month'] = $cursor->isoFormat('MMM');
            }
            $week['days'][] = $day;

            if ($cursor->isSunday()) {
                $weeks[] = $week;
                $week = null;
            }
        }
        if ($week !== null) {
            // Pad the trailing (current) week to 7 slots — the days after
            // today are the future, rendered as null (no cell).
            while (count($week['days']) < 7) {
                $week['days'][] = null;
            }
            $weeks[] = $week;
        }

        return ['weeks' => $weeks];
    }
}
