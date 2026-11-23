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
     * The years the profile graph offers: from the user's EARLIEST incident
     * year through the current year (owner 2026-11-21: "cukup per tahun saja
     * … dan mekanismenya bisa untuk tahun berapapun"). Empty when the user
     * has no incidents — the caller falls back to the current year.
     *
     * @return list<int>
     */
    public static function years(User $user): array
    {
        $earliest = self::query($user)->min('incident_date');
        $firstYear = $earliest !== null ? (int) Carbon::parse($earliest)->year : (int) now()->year;

        return range(max($firstYear, 1900), (int) now()->year);
    }

    /**
     * GitHub-style contribution data for ONE calendar year (owner 2026-11-21:
     * the rolling 12-month mode is gone — "cukup per tahun saja"), Monday-first
     * weeks as columns. Each DAY gets ONE tier — the highest-priority status
     * among that date's incidents (1 running > 2 monitoring > 3 done; owner:
     * "warna sesuai prioritas status yang paling tinggi"). Tier 0 = no
     * incidents; days after today are plain empty cells.
     *
     * @return array{weeks: list<array{month: string|null, days: list<array{date: string, tier: int, count: int, tooltip: string}|null>}>, total: int}
     */
    public static function contributionGraph(User $user, ?int $year = null): array
    {
        $year ??= (int) now()->year;

        $realStart = Carbon::create($year, 1, 1);
        $end = Carbon::create($year, 12, 31)->endOfDay();

        // The grid runs in whole Monday-first weeks; the leading column may
        // belong to the PREVIOUS year (e.g. a 2025 view starting Mon Dec 30).
        // Its label must name the window's first real month, or the first
        // month of the year silently loses its label.
        $start = $realStart->copy()->startOfWeek(Carbon::MONDAY);
        $leadingMonth = $realStart->isoFormat('MMM');

        // Query the REAL window (Jan 1 – Dec 31), not the Monday-aligned grid
        // start: the leading partial week may contain last-year days, and
        // their incidents do not belong to this year's count.
        $incidents = self::query($user)
            ->whereBetween('incident_date', [$realStart, $end])
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
        $today = now()->endOfDay();
        for ($cursor = $start->copy(); $cursor <= $end; $cursor->addDay()) {
            $key = $cursor->format('Y-m-d');
            $tier = $byDay[$key]['tier'] ?? 0;
            $count = $byDay[$key]['count'] ?? 0;
            $label = $cursor->isoFormat('MMM D, YYYY');
            // Rolling mode ends today; year mode keeps future days as plain
            // empty cells (nothing in the future can have an incident).
            $day = $cursor->gt($today) ? [
                'date' => $key,
                'tier' => 0,
                'count' => 0,
                'tooltip' => "No incidents on {$label}",
            ] : [
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
            // Month label: the week that CONTAINS a day-of-month 1 (checked
            // after the day is pushed — the old pre-push check dropped the
            // label whenever the 1st fell on a Monday and OPENED a week, e.g.
            // Jun 2026), plus the leading partial week so the first month is
            // not missing either.
            $week['days'][] = $day;
            if ($week['month'] === null) {
                if ($cursor->isSameDay($start)) {
                    // Leading partial week: name the window's first real month
                    // (JAN for a year view), never the previous month it also
                    // contains — otherwise the year's first month is skipped.
                    $week['month'] = $leadingMonth;
                } elseif ($cursor->day === 1) {
                    $week['month'] = $cursor->isoFormat('MMM');
                }
            }

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

        // Total incident RECORDS in the window (a day with 2 incidents counts
        // 2) — the graph title's "N incidents in the last year" (owner
        // 2026-11-20: total in the graph, not the active-only count).
        $total = array_sum(array_map(fn (array $bucket) => $bucket['count'], $byDay));

        return ['weeks' => $weeks, 'total' => $total];
    }
}
