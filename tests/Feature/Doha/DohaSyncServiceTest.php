<?php

declare(strict_types=1);

namespace Tests\Feature\Doha;

use App\Models\DohaTicket;
use App\Models\DohaTicketChange;
use App\Models\Setting;
use App\Services\Doha\DohaClient;
use App\Services\Doha\DohaSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DohaSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private DohaSyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sync = new DohaSyncService(new DohaClient);
        $this->givenDohaToken();
        config(['doha.retry_backoff' => [0, 0]]);
    }

    private function givenDohaToken(string $token = 'test-token'): void
    {
        Setting::set(DohaClient::TOKEN_KEY, Crypt::encryptString($token));
    }

    private function dohaRow(array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'ref' => 'INC-0050',
            'title' => 'Timeout to database',
            'bank' => 'BCA',
            'domain' => 'Channel',
            'team' => 'Tech Risk',
            'category' => 'Fund Loss',
            'serviceImpact' => 'Payment channel down',
            'rootCause' => 'DB connection pool exhausted',
            'businessType' => 'Internal',
            'totalTransaction' => 12000,
            'potentialLoss' => 1500000,
            'recovered' => 500000,
            'priority' => 'High',
            'status' => 'Ongoing',
            'transactionDate' => '2026-11-20',
            'transactionTime' => '08:30',
            'closeDate' => null,
            'recoveryAction' => 'Failover to replica',
            'followUpAction' => 'Add connection-pool alert',
            'createdBy' => 'doha.user1',
            'createdByEdit' => 'doha.user2',
            'createdAt' => '2026-11-20 03:10:00',
            'updatedAt' => '2026-11-24 10:00:00',
        ], $overrides);
    }

    /**
     * Each response is [body] or [body, status]; the sync calls the list
     * endpoint first, then (when a marker exists) the deleted endpoint.
     */
    private function runSync(array $responses, bool $full = false): array
    {
        $sequence = Http::sequence();
        foreach ($responses as $response) {
            $sequence->push($response[0], $response[1] ?? 200);
        }
        Http::fake(['doha.dana.id/*' => $sequence]);

        return $this->sync->sync($full);
    }

    private function assertNoTicketChange(string $dohaId): void
    {
        $this->assertSame(0,
            DohaTicketChange::whereHas('ticket', fn ($q) => $q->where('doha_id', $dohaId))->count()
        );
    }

    public function test_first_run_full_read_no_updated_since_creates_and_sets_watermark(): void
    {
        $result = $this->runSync([
            [[$this->dohaRow(['updatedAt' => '2026-11-24 10:00:00'])]],
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['created']);
        $this->assertDatabaseCount('doha_tickets', 1);
        $this->assertSame('2026-11-24 10:00:00', Setting::get('doha_sync_watermark'));
        Http::assertSent(fn ($request) => ($request['updatedSince'] ?? null) === null);
    }

    public function test_incremental_run_sends_watermark_verbatim(): void
    {
        Setting::set('doha_sync_watermark', '2026-11-24 10:00:00');

        $this->runSync([
            [[$this->dohaRow(['updatedAt' => '2026-11-24 11:00:00'])]],
            [[]],
        ]);

        Http::assertSent(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/api/incidents')
            && (($request['updatedSince'] ?? null) === '2026-11-24 10:00:00'));
    }

    public function test_pages_until_short_page(): void
    {
        $pageOne = [];
        for ($i = 0; $i < 500; $i++) {
            $pageOne[] = $this->dohaRow(['ref' => 'INC-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
        }
        $pageTwo = [$this->dohaRow(['ref' => 'INC-9999'])];

        $result = $this->runSync([[$pageOne], [$pageTwo]]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(501, $result['created']);
        $this->assertDatabaseCount('doha_tickets', 501);
    }

    public function test_upsert_by_id_absorbs_duplicates(): void
    {
        $id = (string) Str::uuid();
        $row = $this->dohaRow(['id' => $id]);

        $result = $this->runSync([[[$row, $row]]]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertDatabaseCount('doha_tickets', 1);
    }

    public function test_same_second_boundary_reread_is_idempotent(): void
    {
        $id = (string) Str::uuid();
        $row = $this->dohaRow(['id' => $id, 'updatedAt' => '2026-11-24 10:00:00']);
        Setting::set('doha_sync_watermark', '2026-11-24 10:00:00');

        // One fake for BOTH passes — a second Http::fake on the same URL
        // merges stubs and the exhausted first sequence keeps winning.
        Http::fake(['doha.dana.id/*' => Http::sequence()
            ->push([$row], 200)->push([], 200)
            ->push([$row], 200)->push([], 200)]);

        $this->sync->sync();
        $result = $this->sync->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertDatabaseCount('doha_tickets', 1);
        $this->assertSame(0, $result['created']);
        $this->assertNoTicketChange($id);
    }

    public function test_field_change_writes_change_rows_once(): void
    {
        $id = (string) Str::uuid();
        // Start from an exact mirror of dohaRow (differing only in the two
        // fields the change should capture), so exactly 2 change rows appear.
        $ticket = DohaTicket::create([
            'doha_id' => $id,
            'ref' => 'INC-0050',
            'title' => 'Timeout to database',
            'bank' => 'BCA',
            'domain' => 'Channel',
            'team' => 'Tech Risk',
            'category' => 'Fund Loss',
            'service_impact' => 'Payment channel down',
            'root_cause' => 'DB connection pool exhausted',
            'business_type' => 'Internal',
            'total_transaction' => 12000,
            'potential_loss' => 500000,
            'recovered' => 500000,
            'priority' => 'High',
            'status' => 'Ongoing',
            'transaction_date' => '2026-11-20',
            'transaction_time' => '08:30',
            'recovery_action' => 'Failover to replica',
            'follow_up_action' => 'Add connection-pool alert',
            'created_by' => 'doha.user1',
            'created_by_edit' => 'doha.user2',
            'doha_created_at' => '2026-11-20 03:10:00',
            'doha_updated_at' => '2026-11-24 09:00:00',
        ]);

        $result = $this->runSync([[[$this->dohaRow([
            'id' => $id,
            'status' => 'Closed',
            'potentialLoss' => 1000000,
            'updatedAt' => '2026-11-24 10:00:00',
        ])]]]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['updated']);
        $changes = DohaTicketChange::where('doha_ticket_id', $ticket->id)->get();
        $this->assertSame(2, $changes->count());
        $newValues = $changes->pluck('new_value', 'field');
        $oldValues = $changes->pluck('old_value', 'field');
        $this->assertSame('Closed', $newValues['status']);
        $this->assertSame('Ongoing', $oldValues['status']);
        $this->assertSame('1000000.00', $newValues['potential_loss']);
        $this->assertSame('500000.00', $oldValues['potential_loss']);
    }

    public function test_deleted_endpoint_drops_local_tickets(): void
    {
        $id = (string) Str::uuid();
        $ticket = DohaTicket::factory()->create(['doha_id' => $id]);
        DohaTicketChange::create([
            'doha_ticket_id' => $ticket->id,
            'field' => 'status',
            'old_value' => 'Ongoing',
            'new_value' => 'Closed',
        ]);
        Setting::set('doha_sync_watermark', '2026-11-24 10:00:00');

        $result = $this->runSync([
            [[]],
            [[['id' => $id, 'deletedAt' => '2026-11-24 11:00:00']]],
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertDatabaseMissing('doha_tickets', ['doha_id' => $id]);
        $this->assertDatabaseMissing('doha_ticket_changes', ['doha_ticket_id' => $ticket->id]);
        $this->assertSame(1, $result['deleted']);
    }

    public function test_first_run_skips_deleted_endpoint(): void
    {
        $result = $this->runSync([[[]]]);

        $this->assertSame('ok', $result['status']);
        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/api/incidents/deleted'));
    }

    public function test_watermark_advances_only_over_succeeded_rows(): void
    {
        $good = $this->dohaRow(['updatedAt' => '2026-11-24 09:00:00']);
        $poison = $this->dohaRow(['updatedAt' => '2026-11-24 10:00:00']);
        unset($poison['id']);

        $result = $this->runSync([[[ $good, $poison ]]]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('2026-11-24 09:00:00', Setting::get('doha_sync_watermark'),
            'watermark must stay below the poison row so the next run re-reads it');
    }

    public function test_watermark_kept_when_changed_set_empty(): void
    {
        Setting::set('doha_sync_watermark', '2026-11-24 10:00:00');

        $this->runSync([[[]], [[]]]);

        $this->assertSame('2026-11-24 10:00:00', Setting::get('doha_sync_watermark'));
    }

    public function test_full_run_drops_local_ids_no_longer_returned(): void
    {
        $gone = (string) Str::uuid();
        DohaTicket::factory()->create(['doha_id' => $gone]);
        $kept = $this->dohaRow();

        $result = $this->runSync([[[$kept]]], full: true);

        $this->assertSame('ok', $result['status']);
        $this->assertDatabaseMissing('doha_tickets', ['doha_id' => $gone]);
        $this->assertDatabaseCount('doha_tickets', 1);
    }

    public function test_full_run_with_empty_listing_keeps_mirror(): void
    {
        DohaTicket::factory()->create();

        $result = $this->runSync([[[]]], full: true);

        $this->assertSame('ok', $result['status']);
        $this->assertDatabaseCount('doha_tickets', 1);
    }

    public function test_deletes_endpoint_failure_is_non_fatal(): void
    {
        $id = (string) Str::uuid();
        DohaTicket::factory()->create(['doha_id' => $id]);
        Setting::set('doha_sync_watermark', '2026-11-24 10:00:00');

        $result = $this->runSync([
            [[]],
            [['boom'], 500],
            [['boom'], 500],
            [['boom'], 500],
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertDatabaseHas('doha_tickets', ['doha_id' => $id]);
    }

    public function test_concurrent_sync_returns_already_running(): void
    {
        Cache::lock('doha-ticket-sync', 900)->get();

        $result = $this->sync->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('already running', $result['message']);
    }

    public function test_unconfigured_fails_cleanly(): void
    {
        Setting::set(DohaClient::TOKEN_KEY, null);

        $result = $this->sync->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('System Settings', $result['message']);
    }

    public function test_command_reports_sync_result(): void
    {
        Http::fake(['doha.dana.id/*' => Http::response([$this->dohaRow()])]);

        $this->artisan('doha:sync-tickets')->expectsOutputToContain('DOHA tickets')->assertSuccessful();
    }
}
