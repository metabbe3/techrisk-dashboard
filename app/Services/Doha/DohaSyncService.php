<?php

declare(strict_types=1);

namespace App\Services\Doha;

use App\Models\DohaTicket;
use App\Models\DohaTicketChange;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Keeps the doha_tickets mirror in sync with DOHA, following the API guide's
 * official recipe (docha_temp/DOHA API Integration Guide.pdf):
 *
 * 1. First run: full read (no updatedSince), store newest updatedAt.
 * 2. Each poll: updatedSince=<marker>, page until a short page, upsert by id.
 * 3. Deletes: tombstones endpoint with the same marker (best-effort).
 * 4. Move the marker ONLY to the newest successfully-processed row — a
 *    poison row pins the marker below its updatedAt and the inclusive
 *    boundary re-reads it next run (the upsert absorbs the repeats).
 * 5. Weekly full re-read (--full) drops ids DOHA no longer returns.
 */
class DohaSyncService
{
    public const WATERMARK_KEY = 'doha_sync_watermark';

    public const LAST_SYNC_AT_KEY = 'doha_last_sync_at';

    public const LAST_SYNC_STATUS_KEY = 'doha_last_sync_status';

    public const LOCK_KEY = 'doha-ticket-sync';

    private const MAX_PAGES = 1000;

    /**
     * The 21 substantive DOHA fields diffed for change history. createdAt /
     * updatedAt are metadata — refreshed silently, never timeline entries.
     */
    private const FIELDS = [
        'ref' => 'ref',
        'title' => 'title',
        'bank' => 'bank',
        'domain' => 'domain',
        'team' => 'team',
        'category' => 'category',
        'service_impact' => 'serviceImpact',
        'root_cause' => 'rootCause',
        'business_type' => 'businessType',
        'total_transaction' => 'totalTransaction',
        'potential_loss' => 'potentialLoss',
        'recovered' => 'recovered',
        'priority' => 'priority',
        'status' => 'status',
        'transaction_date' => 'transactionDate',
        'transaction_time' => 'transactionTime',
        'close_date' => 'closeDate',
        'recovery_action' => 'recoveryAction',
        'follow_up_action' => 'followUpAction',
        'created_by' => 'createdBy',
        'created_by_edit' => 'createdByEdit',
    ];

    private const DECIMALS = ['total_transaction', 'potential_loss', 'recovered'];

    public function __construct(private readonly DohaClient $client)
    {
    }

    /**
     * @return array{status: string, message: string, created: int, updated: int, deleted: int, failed: int}
     */
    public function sync(bool $full = false): array
    {
        try {
            return Cache::lock(self::LOCK_KEY, 900)->block(10, function () use ($full) {
                return $this->run($full);
            });
        } catch (LockTimeoutException) {
            return [
                'status' => 'failed',
                'message' => 'A DOHA sync is already running — try again in a moment.',
                'created' => 0, 'updated' => 0, 'deleted' => 0, 'failed' => 0,
            ];
        }
    }

    /**
     * @return array{status: string, message: string, created: int, updated: int, deleted: int, failed: int}
     */
    private function run(bool $full): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'failed' => 0];

        if (! $this->client->configured()) {
            return $this->finish('failed', 'DOHA token not configured — set it on the System Settings page.', $counts, record: false);
        }

        $marker = (string) Setting::get(self::WATERMARK_KEY, '');
        $usedMarker = $full || $marker === '' ? null : $marker;

        $returnedIds = [];
        $newestSucceeded = null;

        for ($offset = 0, $pages = 0; $pages < self::MAX_PAGES; $offset += DohaClient::PAGE_SIZE, $pages++) {
            $page = $this->client->incidents($usedMarker, $offset);

            if ($page['error'] !== null) {
                return $this->finish('failed', 'DOHA API error: '.$page['error'], $counts);
            }

            foreach ($page['rows'] as $row) {
                $returnedIds[] = (string) ($row['id'] ?? '');

                try {
                    $result = $this->upsert($row);
                    $counts[$result]++;
                    $newestSucceeded = $this->maxTimestamp($newestSucceeded, $row['updatedAt'] ?? null);
                } catch (\Throwable $exception) {
                    $counts['failed']++;
                    Log::warning('DOHA row skipped', [
                        'id' => $row['id'] ?? null,
                        'ref' => $row['ref'] ?? null,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            if (count($page['rows']) < DohaClient::PAGE_SIZE) {
                break;
            }
        }

        $this->advanceWatermark($newestSucceeded);

        if ($usedMarker !== null) {
            $counts['deleted'] = $this->applyDeletes($usedMarker);
        }

        if ($full && $returnedIds !== []) {
            $counts['deleted'] += $this->dropUnreturned($returnedIds);
        }

        return $this->finish(
            'ok',
            sprintf('Synced DOHA tickets: %d new, %d updated, %d deleted, %d failed.',
                $counts['created'], $counts['updated'], $counts['deleted'], $counts['failed']),
            $counts
        );
    }

    /**
     * @return 'created'|'updated'
     */
    private function upsert(array $row): string
    {
        $dohaId = (string) ($row['id'] ?? throw new \InvalidArgumentException('Missing id'));
        $attributes = $this->mapRow($row);

        $existing = DohaTicket::query()->where('doha_id', $dohaId)->first();

        if ($existing === null) {
            DohaTicket::create($attributes + ['doha_id' => $dohaId]);

            return 'created';
        }

        $changes = [];
        foreach (self::FIELDS as $column => $apiField) {
            $incoming = $attributes[$column];
            $stored = $this->normalizeStored($existing->{$column}, $column);

            if (! $this->sameValue($incoming, $stored, $column)) {
                $changes[] = [
                    'field' => $column,
                    'old_value' => $stored === null ? null : $this->displayValue($stored, $column),
                    'new_value' => $incoming === null ? null : $this->displayValue($incoming, $column),
                ];
            }
        }

        if ($changes !== []) {
            $existing->update($attributes);
            foreach ($changes as $change) {
                DohaTicketChange::create($change + ['doha_ticket_id' => $existing->id]);
            }

            return 'updated';
        }

        // Same-value re-read: refresh metadata stamps only, no change rows.
        $existing->update([
            'created_by_edit' => $attributes['created_by_edit'],
            'doha_updated_at' => $attributes['doha_updated_at'],
        ]);

        return 'updated';
    }

    private function mapRow(array $row): array
    {
        return [
            'ref' => $row['ref'] ?? null,
            'title' => $row['title'] ?? null,
            'bank' => $row['bank'] ?? null,
            'domain' => $row['domain'] ?? null,
            'team' => $row['team'] ?? null,
            'category' => $row['category'] ?? null,
            'service_impact' => $row['serviceImpact'] ?? null,
            'root_cause' => $row['rootCause'] ?? null,
            'business_type' => $row['businessType'] ?? null,
            'total_transaction' => $row['totalTransaction'] ?? null,
            'potential_loss' => $row['potentialLoss'] ?? null,
            'recovered' => $row['recovered'] ?? null,
            'priority' => $row['priority'] ?? null,
            'status' => $row['status'] ?? null,
            'transaction_date' => $this->normalizeDate($row['transactionDate'] ?? null),
            'transaction_time' => $row['transactionTime'] ?? null,
            'close_date' => $this->normalizeDate($row['closeDate'] ?? null),
            'recovery_action' => $row['recoveryAction'] ?? null,
            'follow_up_action' => $row['followUpAction'] ?? null,
            'created_by' => $row['createdBy'] ?? null,
            'created_by_edit' => $row['createdByEdit'] ?? null,
            'doha_created_at' => $row['createdAt'] ?? null,
            'doha_updated_at' => $row['updatedAt'] ?? null,
        ];
    }

    /**
     * DOHA's transactionDate is YYYY-MM-DD; older rows may carry DD-MM-YYYY.
     * Anything else is null — never guess (plan ruling).
     */
    private function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $value, $matches) === 1) {
            return $matches[3].'-'.$matches[2].'-'.$matches[1];
        }

        return null;
    }

    private function sameValue(mixed $incoming, mixed $stored, string $column): bool
    {
        if ($column === 'transaction_date' || $column === 'close_date') {
            return $incoming === ($stored === null ? null : $stored->format('Y-m-d'));
        }

        if (in_array($column, self::DECIMALS, true)) {
            return $incoming === null ? $stored === null : (float) $stored === (float) $incoming;
        }

        return $incoming === $stored;
    }

    /** Values into change rows: decimals 2dp, dates Y-m-d, others (string). */
    private function displayValue(mixed $value, string $column): string
    {
        if (in_array($column, self::DECIMALS, true)) {
            return number_format((float) $value, 2, '.', '');
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }

    private function normalizeStored(mixed $value, string $column): mixed
    {
        if (in_array($column, self::DECIMALS, true) && $value !== null) {
            return (float) $value;
        }

        return $value;
    }

    private function maxTimestamp(?string $current, mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '') {
            return $current;
        }

        return $current === null || $candidate > $current ? $candidate : $current;
    }

    private function advanceWatermark(?string $newestSucceeded): void
    {
        if ($newestSucceeded === null) {
            return;
        }

        $marker = (string) Setting::get(self::WATERMARK_KEY, '');

        if ($newestSucceeded > $marker) {
            Setting::set(self::WATERMARK_KEY, $newestSucceeded);
        }
    }

    private function applyDeletes(string $marker): int
    {
        $deleted = 0;

        for ($offset = 0, $pages = 0; $pages < self::MAX_PAGES; $offset += DohaClient::PAGE_SIZE, $pages++) {
            $page = $this->client->deleted($marker, $offset);

            if ($page['error'] !== null) {
                // Best-effort per the DOHA guide — never fails the sync.
                Log::warning('DOHA deletes endpoint failed (best-effort)', ['error' => $page['error']]);

                break;
            }

            $ids = array_map(
                static fn (array $row) => (string) ($row['id'] ?? ''),
                array_filter($page['rows'], static fn ($row) => is_array($row) && isset($row['id']))
            );

            $deleted += DohaTicket::query()->whereIn('doha_id', $ids)->delete();

            if (count($page['rows']) < DohaClient::PAGE_SIZE) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * @param  list<string>  $returnedIds
     */
    private function dropUnreturned(array $returnedIds): int
    {
        // Defensive: an empty full listing never wipes the mirror (weekly
        // cadence retries); DOHA returning zero rows on a full read is a
        // glitch, not a mass deletion.
        $returned = array_values(array_unique(array_filter($returnedIds)));

        if ($returned === []) {
            return 0;
        }

        $dropped = 0;

        DohaTicket::query()->whereNotIn('doha_id', $returned)->chunkById(500, function ($tickets) use (&$dropped) {
            foreach ($tickets as $ticket) {
                $ticket->delete();
                $dropped++;
            }
        });

        return $dropped;
    }

    /**
     * @param  array{created: int, updated: int, deleted: int, failed: int}  $counts
     * @return array{status: string, message: string, created: int, updated: int, deleted: int, failed: int}
     */
    private function finish(string $status, string $message, array $counts, bool $record = true): array
    {
        if ($record) {
            Setting::set(self::LAST_SYNC_AT_KEY, now()->toIso8601String());
            Setting::set(self::LAST_SYNC_STATUS_KEY, $status.': '.$message);
        }

        if ($status === 'failed') {
            Log::warning('DOHA sync failed', ['message' => $message]);
        }

        return $counts + ['status' => $status, 'message' => $message];
    }
}
