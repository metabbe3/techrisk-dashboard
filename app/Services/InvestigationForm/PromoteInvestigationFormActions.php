<?php

declare(strict_types=1);

namespace App\Services\InvestigationForm;

use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\InvestigationFormRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Promotes structured action items from a submitted InvestigationFormRequest
 * into real ActionImprovement rows on the parent incident (D1).
 */
class PromoteInvestigationFormActions
{
    /**
     * Map answers['action_items'] into editable Filament Repeater draft rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function draftRows(InvestigationFormRequest $request): array
    {
        $rawItems = $request->answers[InvestigationFormSchema::SECTION_ACTION_ITEMS] ?? [];
        if (! is_array($rawItems) || $rawItems === []) {
            return [];
        }

        $existingTitles = $request->incident
            ->actionImprovements()
            ->pluck('title')
            ->map(fn (string $t): string => Str::lower(trim($t)))
            ->all();

        $rows = [];
        foreach ($rawItems as $row) {
            if (! is_array($row)) {
                continue;
            }

            $item = trim((string) ($row['item'] ?? ''));
            if ($item === '') {
                continue;
            }

            $verification = trim((string) ($row['verification'] ?? ''));
            $ownerRaw = trim((string) ($row['owner'] ?? ''));
            $resolvedEmail = $this->resolveOwnerEmail($ownerRaw);

            $detailParts = [$item];
            if ($verification !== '') {
                $detailParts[] = "Verification standard: {$verification}";
            }
            if ($ownerRaw !== '' && $resolvedEmail === null) {
                $detailParts[] = "Form owner: {$ownerRaw}";
            }
            if (filled($request->team)) {
                $detailParts[] = "Source: Investigation Form ({$request->team})";
            }

            $title = Str::limit($item, 255, '');

            $rows[] = [
                'title' => $title,
                'detail' => implode("\n", $detailParts),
                'due_date' => $this->parseDueDate((string) ($row['due_date'] ?? '')),
                'pic_email' => $resolvedEmail !== null ? [$resolvedEmail] : [],
                'priority' => 'medium',
                'status' => ActionImprovement::STATUS_PENDING,
                'already_exists' => in_array(Str::lower(trim($title)), $existingTitles, true),
            ];
        }

        return $rows;
    }

    /**
     * Persist reviewed rows as ActionImprovement records in one transaction.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, ActionImprovement>
     */
    public function import(Incident $incident, array $rows): Collection
    {
        return DB::transaction(function () use ($incident, $rows): Collection {
            $created = collect();

            foreach ($rows as $row) {
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    continue;
                }

                $detail = trim((string) ($row['detail'] ?? '')) ?: $title;
                $dueDate = filled($row['due_date'] ?? null) ? $this->parseDueDate((string) $row['due_date']) : null;
                $picEmails = array_values(array_unique(array_filter(
                    array_map(fn ($e) => strtolower(trim((string) $e)), (array) ($row['pic_email'] ?? [])),
                    fn (string $e): bool => $e !== ''
                )));

                $priority = in_array($row['priority'] ?? '', ActionImprovement::PRIORITIES, true)
                    ? $row['priority']
                    : 'medium';

                $status = in_array($row['status'] ?? '', [
                    ActionImprovement::STATUS_DRAFT,
                    ActionImprovement::STATUS_PENDING,
                    ActionImprovement::STATUS_ON_PROGRESS,
                    ActionImprovement::STATUS_TBD,
                ], true) ? $row['status'] : ActionImprovement::STATUS_PENDING;

                $created->push($incident->actionImprovements()->create([
                    'title' => Str::limit($title, 255, ''),
                    'detail' => $detail,
                    'due_date' => $dueDate,
                    'pic_email' => $picEmails,
                    'priority' => $priority,
                    'status' => $status,
                    'reminder' => true,
                    'reminder_frequency' => 'weekly',
                ]));
            }

            return $created;
        });
    }

    private function parseDueDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || strcasecmp($raw, 'tbd') === 0) {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $m[1])->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return CarbonImmutable::parse($raw)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveOwnerEmail(string $owner): ?string
    {
        if ($owner === '') {
            return null;
        }

        // Direct email address (or "Name <email>" form)
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $owner, $m)) {
            $email = strtolower($m[0]);
            $match = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where(fn ($q) => $q->where('is_service_account', false)->orWhereNull('is_service_account'))
                ->value('email');

            return $match ?? $email;
        }

        // Exact case-insensitive name match against the human directory
        $match = User::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($owner)])
            ->where(fn ($q) => $q->where('is_service_account', false)->orWhereNull('is_service_account'))
            ->value('email');

        return $match !== null ? (string) $match : null;
    }
}
