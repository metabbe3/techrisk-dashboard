<?php

namespace Tests\Feature\Notion;

use App\Enums\FundStatus;
use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notion\NotionInvestigationService;
use App\Services\Notion\NotionMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class NotionInvestigationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected const PARENT_ID = 'aa11bb22cc33dd44ee55ff6600778899';

    protected const CREATED_ID = '1234abcd5678ef901234abcd5678ef90';

    /** The fresh page a re-investigation creates (never the deleted one). */
    protected const NEW_ID = 'fedcba0987654321fedcba0987654321';

    protected const DS_ID = 'ce92556e44dd82569ec8871ba6130e54';

    protected const DS_ID_DASHED = 'ce92556e-44dd-8256-9ec8-871ba6130e54';

    /** The Master DB's own database ID (what a pasted database URL holds). */
    protected const DB_ID = '3982556e44dd80bc985efaafea444855';

    /** The Incident Investigation Master DB's default template id (schema URL form / dashed — both accepted). */
    protected const TEMPLATE_ID = '6892556e44dd827da31f010e30a2485a';

    protected const TEMPLATE_ID_DASHED = '6892556e-44dd-827d-a31f-010e30a2485a';

    public function test_start_fails_without_a_configured_parent_page(): void
    {
        $incident = $this->incident();

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('parent page', $result['message']);
        $this->assertNull($incident->fresh()->notion_investigation_page_id);
    }

    public function test_start_fails_when_notion_is_not_connected(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, self::PARENT_ID);
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->once()->andReturn(false);

        $result = app(NotionInvestigationService::class)->start($this->incident());

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('not connected', $result['message']);
    }

    public function test_start_refuses_when_an_investigation_page_already_exists(): void
    {
        // No client expectations: any Notion call would fail the test — the
        // guard must fire before the client is touched.
        $incident = $this->incident(['notion_investigation_page_id' => self::CREATED_ID]);

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('already exists', $result['message']);
    }

    /** Owner 2026-11-04 (round 9): Start Investigation is incident-only. */
    public function test_start_is_incident_only(): void
    {
        // No client expectations: any Notion call would fail the test — the
        // guard must fire before the client is touched.
        $issue = $this->incident(['classification' => IncidentClassification::Issue]);

        $result = app(NotionInvestigationService::class)->start($issue);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Only incidents', $result['message']);
        $this->assertNull($issue->fresh()->notion_investigation_page_id);
    }

    /** Owner 2026-11-04: the title is "<yyyymmdd> - <title>" off the incident date. */
    public function test_start_titles_the_page_the_date_and_the_record_title(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, self::PARENT_ID);
        $incident = $this->incidentWithDates(); // incident date 2026-11-01
        $this->mockConnectedCreate(
            titleMustContain: '20261101 - '.$incident->title,
            response: ['structured' => ['id' => self::CREATED_ID], 'text' => 'Created page.', 'isError' => false]
        );

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('ok', $result['status']);
    }

    /** Round 7: titles that carry their own date prefix must not repeat it next to the composed one. */
    public function test_start_does_not_duplicate_a_date_already_in_the_record_title(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, self::PARENT_ID);
        $incident = $this->incident([
            'title' => '31 October 2026 - apmobileprod High Error timeout to mpaas-pushcore',
            'incident_date' => '2026-10-31 10:00:00',
        ]);
        $this->mockConnectedCreate(
            titleMustContain: '20261031 - apmobileprod High Error timeout to mpaas-pushcore',
            response: ['structured' => ['id' => self::CREATED_ID], 'text' => 'Created page.', 'isError' => false]
        );

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('ok', $result['status']);
    }

    public function test_start_creates_the_page_under_the_configured_parent(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, self::PARENT_ID);
        $incident = $this->incident();
        $this->mockConnectedCreate(
            titleMustContain: $incident->title,
            response: ['structured' => ['id' => self::CREATED_ID], 'text' => 'Created page.', 'isError' => false]
        );

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('ok', $result['status']);

        $fresh = $incident->fresh();
        $this->assertSame(self::CREATED_ID, $fresh->notion_investigation_page_id);
        $this->assertStringContainsString($incident->no, $fresh->notion_investigation_markdown);
        $this->assertNotNull($fresh->notion_investigation_synced_at);
        $this->assertSame(IncidentStatus::InProgress->value, $fresh->incident_status->value);
    }

    public function test_start_parses_the_created_page_id_from_the_response_text(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, self::PARENT_ID);
        $incident = $this->incident();
        $this->mockConnectedCreate(
            titleMustContain: $incident->title,
            response: ['structured' => null, 'text' => 'Created https://www.notion.so/'.self::CREATED_ID, 'isError' => false]
        );

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(self::CREATED_ID, $incident->fresh()->notion_investigation_page_id);
    }

    public function test_start_fails_when_the_created_page_id_cannot_be_parsed(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, self::PARENT_ID);
        $incident = $this->incident();
        $this->mockConnectedCreate(
            titleMustContain: $incident->title,
            response: ['structured' => null, 'text' => 'Page created successfully.', 'isError' => false]
        );

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('failed', $result['status']);

        $fresh = $incident->fresh();
        $this->assertNull($fresh->notion_investigation_page_id);
        $this->assertNull($fresh->notion_investigation_markdown);
        $this->assertNull($fresh->notion_investigation_synced_at);
    }

    public function test_start_does_not_change_status_when_page_creation_fails(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, self::PARENT_ID);
        $incident = $this->incident();
        $this->mockConnectedCreate(
            titleMustContain: $incident->title,
            response: ['structured' => null, 'text' => 'boom', 'isError' => true]
        );

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('failed', $result['status']);

        $fresh = $incident->fresh();
        $this->assertSame(IncidentStatus::Open->value, $fresh->incident_status->value);
        $this->assertNull($fresh->notion_investigation_page_id);
    }

    /**
     * A database row is created from the DB's own default template: properties
     * only (no content) under a DASHED data_source_id, then the default
     * template is applied and the resulting body fetched back.
     */
    public function test_start_creates_the_page_under_a_data_source_parent(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, 'collection://'.self::DS_ID);
        $incident = $this->incidentWithDates();
        // Live response shape: structured null, text = JSON with the dashed id
        // + undashed URL — plus the parent echoed FIRST, which must NOT be picked.
        $this->mockDataSourceCreate($incident, function (array $page, array $parent) {
            // create-pages resolves a DASHED data_source_id only — the
            // undashed form fails with object_not_found (live 2026-11-03)
            // even though notion-fetch accepts either.
            $this->assertSame('ce92556e-44dd-8256-9ec8-871ba6130e54', $parent['data_source_id']);
            $this->assertArrayNotHasKey('page_id', $parent);
            $this->assertArrayNotHasKey('content', $page);
        });

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(self::CREATED_ID, $incident->fresh()->notion_investigation_page_id);
        $this->assertSame(IncidentStatus::InProgress->value, $incident->fresh()->incident_status->value);
    }

    /** apply_template runs right after create, with the schema's template id. */
    public function test_start_applies_the_databases_default_template(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, 'collection://'.self::DS_ID);
        $incident = $this->incidentWithDates();
        $mock = $this->mock(NotionMcpClient::class);
        $mock->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::DS_ID])
            ->andReturn($this->dataSourceSchemaResponse())
            ->shouldReceive('callToolDetailed')->once()->with('notion-create-pages', Mockery::any())
            ->andReturn(['structured' => null, 'text' => '{"pages":[{"id":"'.self::CREATED_ID.'"}]}', 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-update-page', [
                'page_id' => self::CREATED_ID,
                'command' => 'apply_template',
                'template_id' => self::TEMPLATE_ID_DASHED,
                'allow_async' => false,
            ])
            ->andReturn(['structured' => null, 'text' => '{"page_id":"..."}', 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->pageFetchResponse('# Table of Content'));

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('# Table of Content', $incident->fresh()->notion_investigation_markdown);
        $this->assertNotNull($incident->fresh()->notion_investigation_synced_at);
    }

    /**
     * The row's properties fill from the portal record, in the SQLite
     * format. Incident Date is the portal form's "Occurred Time"
     * (`incident_date`, owner round 13); Entry Date is the portal record's
     * CREATED date (owner round 13 — was `entry_date_tech_risk`).
     */
    public function test_start_fills_incident_and_entry_dates_on_a_data_source_row(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, 'collection://'.self::DS_ID);
        $incident = $this->incident([
            'incident_date' => '2026-11-01',
            'created_at' => '2026-10-30 15:12:00',
        ]);
        $this->mockDataSourceCreate($incident, function (array $page) use ($incident) {
            $properties = $page['properties'];

            $this->assertSame('2026-11-01', $properties['date:Incident Date:start']);
            $this->assertSame('2026-10-30', $properties['date:Entry Date:start']);
            $this->assertSame('20261101 - '.$incident->title, (string) $properties['Name']);
        });

        app(NotionInvestigationService::class)->start($incident);
    }

    /**
     * Owner round 13: "Incident Category" carries the portal severity and
     * "Fund Loss" the portal's fund-loss linkage (any fund status that is
     * not "Non fundLoss" — Non fundLoss/empty = No), both as select option
     * names (live-probed 2026-11-04: the wire format is the plain string).
     */
    public function test_start_fills_incident_category_and_fund_loss_from_the_portal_record(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, 'collection://'.self::DS_ID);
        $incident = $this->incident([
            'severity' => Severity::P2,
            'fund_status' => FundStatus::ConfirmedLoss,
        ]);
        $this->mockDataSourceCreate($incident, function (array $page) {
            $this->assertSame('P2', $page['properties']['Incident Category']);
            $this->assertSame('Yes', $page['properties']['Fund Loss']);
        });

        app(NotionInvestigationService::class)->start($incident);
    }

    /** Non fundLoss and an unset fund status are both "No" (no linkage). */
    public function test_start_sends_fund_loss_no_when_there_is_no_linkage(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, 'collection://'.self::DS_ID);

        foreach ([FundStatus::NonFundLoss, null] as $status) {
            $incident = $this->incident(['fund_status' => $status]);
            $this->mockDataSourceCreate($incident, function (array $page) {
                $this->assertSame('No', $page['properties']['Fund Loss']);
            });

            app(NotionInvestigationService::class)->start($incident);
        }
    }

    /** The portal's count-excluded severities fill Notion's "N" option. */
    public function test_start_maps_the_non_incident_severities_to_the_n_option(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, 'collection://'.self::DS_ID);

        foreach ([Severity::G, Severity::NonIncident] as $severity) {
            $incident = $this->incident(['severity' => $severity]);
            $this->mockDataSourceCreate($incident, function (array $page) {
                $this->assertSame('N', $page['properties']['Incident Category']);
            });

            app(NotionInvestigationService::class)->start($incident);
        }
    }

    /** A severity the database has no option for is skipped, with a note — never an error. */
    public function test_start_skips_a_severity_the_database_has_no_option_for(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, 'collection://'.self::DS_ID);
        $incident = $this->incident(['severity' => Severity::X1]);
        $this->mockDataSourceCreate($incident, function (array $page) {
            $this->assertArrayNotHasKey('Incident Category', $page['properties']);
        });

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('ok', $result['status']);
        $this->assertStringContainsString('X1', $result['message']);
    }

    /** Only properties the database actually has are sent (other DBs must not error). */
    public function test_start_skips_properties_absent_from_the_schema(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, 'collection://'.self::DS_ID);
        $incident = $this->incidentWithDates();
        $this->mockDataSourceCreate(
            $incident,
            function (array $page) {
                $this->assertArrayNotHasKey('date:Entry Date:start', $page['properties']);
                $this->assertArrayNotHasKey('PIC', $page['properties']);
                $this->assertArrayNotHasKey('Incident Category', $page['properties']);
                $this->assertArrayNotHasKey('Fund Loss', $page['properties']);
            },
            schema: [
                'Name' => ['type' => 'title', 'name' => 'Name'],
                'Incident Date' => ['type' => 'date', 'name' => 'Incident Date'],
            ]
        );

        app(NotionInvestigationService::class)->start($incident);
    }

    /**
     * PIC = the triggering user + the portal PICs, resolved to Notion user
     * ids and deduplicated (the trigger is often a PIC too).
     */
    public function test_start_fills_pic_with_the_trigger_and_portal_pics_deduplicated(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, 'collection://'.self::DS_ID);
        $incident = $this->incidentWithDates();

        $trigger = User::factory()->create(['email' => 'Trigger@techrisk.com']);
        $pic = User::factory()->create(['email' => 'pic@techrisk.com']);
        $incident->pics()->attach([$pic->id, $trigger->id]);
        $triggerId = '11111111-2222-4333-8444-555555555555';
        $picId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::DS_ID])
            ->andReturn($this->dataSourceSchemaResponse())
            ->shouldReceive('callToolDetailed')->once()->with('notion-get-users', ['query' => 'Trigger@techrisk.com'])
            ->andReturn(['structured' => null, 'isError' => false, 'text' => $this->userSearchResponse([$triggerId, 'T', 'trigger@techrisk.com'])])
            ->shouldReceive('callToolDetailed')->once()->with('notion-get-users', ['query' => 'pic@techrisk.com'])
            ->andReturn(['structured' => null, 'isError' => false, 'text' => $this->userSearchResponse([$picId, 'P', 'pic@techrisk.com'])])
            ->shouldReceive('callToolDetailed')->once()->with('notion-create-pages', Mockery::on(
                function (array $args) use ($triggerId, $picId) {
                    $this->assertEqualsCanonicalizing(
                        [$triggerId, $picId],
                        $args['pages'][0]['properties']['PIC'] ?? null
                    );

                    return true;
                }
            ))
            ->andReturn(['structured' => null, 'text' => '{"pages":[{"id":"'.self::CREATED_ID.'"}]}', 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-update-page', Mockery::any())
            ->andReturn(['structured' => null, 'text' => '{}', 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->pageFetchResponse('# Body'));

        $result = app(NotionInvestigationService::class)->start($incident, $trigger);

        $this->assertSame('ok', $result['status']);
    }

    /** A fuzzy user-search hit that is NOT the queried email must not be used. */
    public function test_start_uses_only_exact_email_matches_for_pic(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, 'collection://'.self::DS_ID);
        $incident = $this->incidentWithDates();

        $trigger = User::factory()->create(['email' => 'tomy@techrisk.com']);
        // The search echoes a DIFFERENT address that merely contains the query.
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::DS_ID])
            ->andReturn($this->dataSourceSchemaResponse())
            ->shouldReceive('callToolDetailed')->once()->with('notion-get-users', ['query' => 'tomy@techrisk.com'])
            ->andReturn(['structured' => null, 'isError' => false, 'text' => $this->userSearchResponse([
                '99999999-9999-4999-8999-999999999999', 'Tomy S', 'tomy.supriyadi@techrisk.com',
            ])])
            ->shouldReceive('callToolDetailed')->once()->with('notion-create-pages', Mockery::on(
                function (array $args) {
                    $this->assertArrayNotHasKey('PIC', $args['pages'][0]['properties']);

                    return true;
                }
            ))
            ->andReturn(['structured' => null, 'text' => '{"pages":[{"id":"'.self::CREATED_ID.'"}]}', 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-update-page', Mockery::any())
            ->andReturn(['structured' => null, 'text' => '{}', 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->pageFetchResponse('# Body'));

        $result = app(NotionInvestigationService::class)->start($incident, $trigger);

        $this->assertSame('ok', $result['status']);
        $this->assertStringContainsString('PIC', $result['message']);
    }

    /** The row is created and linked even when the body fetch comes back blank. */
    public function test_start_links_the_page_when_the_body_fetch_stays_blank(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, 'collection://'.self::DS_ID);
        $incident = $this->incidentWithDates();

        $client = $this->mock(NotionMcpClient::class);
        $service = new NotionInvestigationService($client, 0);

        $client->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::DS_ID])
            ->andReturn($this->dataSourceSchemaResponse())
            ->shouldReceive('callToolDetailed')->once()->with('notion-create-pages', Mockery::any())
            ->andReturn(['structured' => null, 'text' => '{"pages":[{"id":"'.self::CREATED_ID.'"}]}', 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-update-page', Mockery::any())
            ->andReturn(['structured' => null, 'text' => '{}', 'isError' => false])
            ->shouldReceive('callToolDetailed')->times(3)->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->blankPageFetchResponse());

        $result = $service->start($incident);

        $this->assertSame('ok', $result['status']);

        $fresh = $incident->fresh();
        $this->assertSame(self::CREATED_ID, $fresh->notion_investigation_page_id);
        $this->assertNull($fresh->notion_investigation_markdown);
        $this->assertNull($fresh->notion_investigation_synced_at);
        $this->assertSame(IncidentStatus::InProgress->value, $fresh->incident_status->value);
    }

    /** A template failure must not fail the start — the row exists and is linked. */
    public function test_start_survives_a_template_application_failure(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, 'collection://'.self::DS_ID);
        $incident = $this->incidentWithDates();

        $client = $this->mock(NotionMcpClient::class);
        $service = new NotionInvestigationService($client, 0);

        $client->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::DS_ID])
            ->andReturn($this->dataSourceSchemaResponse())
            ->shouldReceive('callToolDetailed')->once()->with('notion-create-pages', Mockery::any())
            ->andReturn(['structured' => null, 'text' => '{"pages":[{"id":"'.self::CREATED_ID.'"}]}', 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-update-page', Mockery::any())
            ->andReturn(['structured' => null, 'text' => 'boom', 'isError' => true])
            // The blank body retries (delay 0) before giving up.
            ->shouldReceive('callToolDetailed')->times(3)->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->blankPageFetchResponse());

        $result = $service->start($incident);

        $this->assertSame('ok', $result['status']);
        $this->assertStringContainsString('template', $result['message']);

        $fresh = $incident->fresh();
        $this->assertSame(self::CREATED_ID, $fresh->notion_investigation_page_id);
        $this->assertSame(IncidentStatus::InProgress->value, $fresh->incident_status->value);
        $this->assertNull($fresh->notion_investigation_markdown);
    }

    public function test_start_fails_when_the_parent_cannot_be_read_from_notion(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, self::DS_ID);
        $incident = $this->incident();
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::DS_ID])
            ->andReturn(['structured' => null, 'text' => 'nope', 'isError' => true]);

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('parent', $result['message']);
        $this->assertNull($incident->fresh()->notion_investigation_page_id);
    }

    /**
     * A bare data-source ID stored without the collection:// prefix still
     * routes as a database row — the parent's type is detected by fetching
     * it, not by the prefix (the operator's ID was stored bare and the
     * prefix-based routing failed create-pages with object_not_found).
     */
    public function test_start_detects_a_bare_data_source_id_as_a_database_parent(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, self::DS_ID);
        $incident = $this->incidentWithDates();
        $this->mockDataSourceCreate($incident, function (array $page, array $parent) {
            $this->assertSame('ce92556e-44dd-8256-9ec8-871ba6130e54', $parent['data_source_id']);
            $this->assertArrayNotHasKey('page_id', $parent);
        });

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(self::CREATED_ID, $incident->fresh()->notion_investigation_page_id);
    }

    /** A fetched database whose state cannot be parsed fails with the schema message. */
    public function test_start_fails_when_a_detected_database_state_is_unparseable(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, self::DS_ID);
        $incident = $this->incident();
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::DS_ID])
            ->andReturn(['structured' => null, 'isError' => false, 'text' => json_encode([
                'metadata' => ['type' => 'database'],
                'text' => '<database><data-source-state>not json</data-source-state></database>',
            ])]);

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('schema', $result['message']);
    }

    /** The parent echoed BEFORE the created ID must be skipped, not abort parsing. */
    public function test_start_skips_an_echoed_parent_id_and_finds_the_created_page(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, self::PARENT_ID);
        $incident = $this->incident();
        $this->mockConnectedCreate(
            titleMustContain: $incident->title,
            response: ['structured' => null, 'text' => 'parent '.self::PARENT_ID.' then https://www.notion.so/'.self::CREATED_ID, 'isError' => false]
        );

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(self::CREATED_ID, $incident->fresh()->notion_investigation_page_id);
    }

    /** The stored markdown is the page BODY, not Notion's JSON envelope. */
    public function test_refresh_stores_the_extracted_page_body(): void
    {
        $incident = $this->incident(['notion_investigation_page_id' => self::CREATED_ID]);
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->pageFetchResponse("# Investigation — updated body\n\n- finding one"));

        $result = app(NotionInvestigationService::class)->refresh($incident);

        $this->assertSame('ok', $result['status']);

        $fresh = $incident->fresh();
        $this->assertSame("# Investigation — updated body\n\n- finding one", $fresh->notion_investigation_markdown);
        $this->assertNotNull($fresh->notion_investigation_synced_at);
    }

    /**
     * Owner 2026-11-04 (round 8): the page envelope renders Notion AI blocks
     * only as self-closing `<unknown … alt="ai_block" />` placeholders — the
     * generated content lives in the block itself, fetched by its anchor id
     * (live-verified: a generated block returns its content; an empty one
     * returns <blank-page>). Refresh inlines the generated content.
     * Round 10 ("table content not fully imported"): an UN-GENERATED block
     * keeps its placeholder — dropping it deleted the RCA/MOM/Invitation
     * sections from the stored copy, and those blocks are where the
     * table-heavy generated content later lands.
     */
    public function test_refresh_inlines_generated_ai_block_content_and_keeps_untouched_ones(): void
    {
        $incident = $this->incident(['notion_investigation_page_id' => self::CREATED_ID]);

        $filledAnchor = 'c952556e44dd83e8aac681b12ae695f8';
        $blankAnchor = '2d92556e44dd826b92248105a85a9085';
        $placeholder = fn (string $anchor) => '<unknown url="https://app.notion.com/p/'.self::CREATED_ID."#{$anchor}\" alt=\"ai_block\" />";

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->pageFetchResponse(
                "<h2>Invitation Email</h2>\n\n".$placeholder($filledAnchor)."\n\n<h2>RCA</h2>\n\n".$placeholder($blankAnchor),
            ))
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => $filledAnchor])
            ->andReturn($this->blockFetchResponse($filledAnchor, "<b>Subject: Retrospective Invitation</b>\n\nDear all,"))
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => $blankAnchor])
            ->andReturn($this->blockFetchResponse($blankAnchor, null));

        $result = app(NotionInvestigationService::class)->refresh($incident);

        $this->assertSame('ok', $result['status']);

        $body = (string) $incident->fresh()->notion_investigation_markdown;
        $this->assertStringContainsString('<b>Subject: Retrospective Invitation</b>', $body);
        $this->assertStringContainsString('<h2>RCA</h2>', $body);
        // The not-yet-generated block survives verbatim — a later refresh
        // (after the operator runs it in Notion) inlines its content.
        $this->assertStringContainsString($placeholder($blankAnchor), $body);
    }

    /** A block fetch that errors leaves its placeholder in place — the next refresh retries it. */
    public function test_refresh_keeps_the_placeholder_when_an_ai_block_fetch_fails(): void
    {
        $incident = $this->incident(['notion_investigation_page_id' => self::CREATED_ID]);

        $anchor = 'c952556e44dd83e8aac681b12ae695f8';

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->pageFetchResponse(
                '<unknown url="https://app.notion.com/p/'.self::CREATED_ID."#{$anchor}\" alt=\"ai_block\" />",
            ))
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => $anchor])
            ->andReturn(['structured' => null, 'text' => 'boom', 'isError' => true]);

        $result = app(NotionInvestigationService::class)->refresh($incident);

        $this->assertSame('ok', $result['status']);
        $this->assertStringContainsString('alt="ai_block"', (string) $incident->fresh()->notion_investigation_markdown);
    }

    public function test_refresh_fails_without_a_stored_page(): void
    {
        $incident = $this->incident();

        $result = app(NotionInvestigationService::class)->refresh($incident);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('No investigation page', $result['message']);
    }

    public function test_refresh_failure_keeps_the_stored_markdown(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'old body',
            'notion_investigation_synced_at' => '2026-01-01 00:00:00',
        ]);
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn(['structured' => null, 'text' => 'boom', 'isError' => true]);

        $result = app(NotionInvestigationService::class)->refresh($incident);

        $this->assertSame('failed', $result['status']);

        $fresh = $incident->fresh();
        $this->assertSame('old body', $fresh->notion_investigation_markdown);
        $this->assertSame('2026-01-01 00:00:00', $fresh->notion_investigation_synced_at->format('Y-m-d H:i:s'));
    }

    /** A valid fetch whose body cannot be read must not wipe the stored copy. */
    public function test_refresh_keeps_the_stored_markdown_when_the_body_cannot_be_read(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'old body',
            'notion_investigation_synced_at' => '2026-01-01 00:00:00',
        ]);
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn(['structured' => null, 'text' => 'not json at all', 'isError' => false]);

        $result = app(NotionInvestigationService::class)->refresh($incident);

        $this->assertSame('failed', $result['status']);

        $fresh = $incident->fresh();
        $this->assertSame('old body', $fresh->notion_investigation_markdown);
        $this->assertSame('2026-01-01 00:00:00', $fresh->notion_investigation_synced_at->format('Y-m-d H:i:s'));
    }

    public function test_unlink_fails_without_a_linked_page(): void
    {
        $result = app(NotionInvestigationService::class)->unlink($this->incident());

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('No investigation page', $result['message']);
    }

    /**
     * Owner 2026-11-04 (round 10): unlinking a LIVE page is soft — the page
     * ID stays so the section survives and "Relink" (or Start Investigation)
     * picks the same page back up; only the stored copy clears.
     */
    public function test_unlink_on_an_alive_page_is_soft_and_keeps_the_page_id(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'old body',
            'notion_investigation_synced_at' => '2026-01-01 00:00:00',
        ]);

        $result = app(NotionInvestigationService::class)->unlink($incident);

        $this->assertSame('ok', $result['status']);
        $this->assertStringContainsString('Relink', $result['message']);

        $fresh = $incident->fresh();
        $this->assertSame(self::CREATED_ID, $fresh->notion_investigation_page_id);
        $this->assertNull($fresh->notion_investigation_markdown);
        $this->assertNull($fresh->notion_investigation_synced_at);
        $this->assertNull($fresh->notion_investigation_deleted_at);
        $this->assertNotNull($fresh->notion_investigation_unlinked_at);
    }

    /**
     * A FLAGGED page has nothing to relink (Notion's trash is out of reach)
     * — its unlink clears the whole link so Start Investigation can run
     * fresh; re-investigation composes exactly this way.
     */
    public function test_unlink_on_a_flagged_page_clears_everything(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'old body',
            'notion_investigation_synced_at' => '2026-01-01 00:00:00',
            'notion_investigation_deleted_at' => '2026-11-04 00:00:00',
            'incident_status' => IncidentStatus::InProgress->value,
        ]);

        $result = app(NotionInvestigationService::class)->unlink($incident);

        $this->assertSame('ok', $result['status']);

        $fresh = $incident->fresh();
        $this->assertNull($fresh->notion_investigation_page_id);
        $this->assertNull($fresh->notion_investigation_markdown);
        $this->assertNull($fresh->notion_investigation_synced_at);
        $this->assertNull($fresh->notion_investigation_deleted_at);
        $this->assertNull($fresh->notion_investigation_unlinked_at);
        $this->assertSame(IncidentStatus::InProgress->value, $fresh->incident_status->value);
    }

    /**
     * Owner 2026-11-04 (round 10): a refresh of a soft-unlinked record IS
     * the relink — the stored page id is re-read, the body re-imported, the
     * flag cleared.
     */
    public function test_refresh_relinks_a_soft_unlinked_record(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_unlinked_at' => '2026-11-04 00:00:00',
        ]);
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->pageFetchResponse('# Continued body'));

        $result = app(NotionInvestigationService::class)->refresh($incident);

        $this->assertSame('ok', $result['status']);

        $fresh = $incident->fresh();
        $this->assertSame(self::CREATED_ID, $fresh->notion_investigation_page_id);
        $this->assertSame('# Continued body', $fresh->notion_investigation_markdown);
        $this->assertNotNull($fresh->notion_investigation_synced_at);
        $this->assertNull($fresh->notion_investigation_unlinked_at);
    }

    /**
     * Owner 2026-11-04 (round 10): Start Investigation on a soft-unlinked
     * record CONTINUES the existing page — no new page is created.
     */
    public function test_start_on_a_soft_unlinked_record_continues_the_existing_page(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_unlinked_at' => '2026-11-04 00:00:00',
            'notion_investigation_markdown' => null,
            'incident_status' => IncidentStatus::InProgress->value,
        ]);
        // The only Notion call is the page's own fetch — any create call
        // would make `once()` fail.
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->pageFetchResponse('# Continued body'));

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('ok', $result['status']);
        $this->assertStringContainsString('Continued the existing investigation page', $result['message']);

        $fresh = $incident->fresh();
        $this->assertSame(self::CREATED_ID, $fresh->notion_investigation_page_id);
        $this->assertSame('# Continued body', $fresh->notion_investigation_markdown);
        $this->assertNull($fresh->notion_investigation_unlinked_at);
        $this->assertSame(IncidentStatus::InProgress->value, $fresh->incident_status->value);
    }

    /**
     * Owner 2026-11-04 (round 10): a page deleted in Notion cannot be
     * continued — Start Investigation falls through and creates a FRESH
     * page (the deleted one stays in Notion's trash).
     */
    public function test_start_on_a_soft_unlinked_record_creates_a_fresh_page_when_the_old_one_is_deleted(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, self::PARENT_ID);
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_unlinked_at' => '2026-11-04 00:00:00',
        ]);
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn(['structured' => null, 'isError' => false, 'text' => json_encode([
                'metadata' => ['type' => 'page'],
                'text' => '<page url="https://app.notion.com/p/'.self::CREATED_ID.'" deleted>'
                    .'<blank-page>This page is blank and has no content.</blank-page></page>',
            ])])
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::PARENT_ID])
            ->andReturn($this->pageParentResponse())
            ->shouldReceive('callToolDetailed')->once()->with('notion-create-pages', Mockery::on(
                fn (array $args) => ($args['parent']['page_id'] ?? null) === self::PARENT_ID
                    && is_array($args['pages'][0] ?? null)
            ))
            ->andReturn(['structured' => null, 'text' => '{"pages":[{"id":"'.self::NEW_ID.'"}]}', 'isError' => false]);

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('ok', $result['status']);
        $this->assertStringContainsString('Investigation page created', $result['message']);

        $fresh = $incident->fresh();
        $this->assertSame(self::NEW_ID, $fresh->notion_investigation_page_id);
        $this->assertNull($fresh->notion_investigation_deleted_at);
        $this->assertNull($fresh->notion_investigation_unlinked_at);
        $this->assertSame(IncidentStatus::InProgress->value, $fresh->incident_status->value);
    }

    /** A page deleted in Notion fetches WITHOUT an error — it returns <page … deleted>. Round 9: the fetch flags the record for Re-investigate. */
    public function test_refresh_reports_a_deleted_notion_page(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'old body',
            'notion_investigation_synced_at' => '2026-01-01 00:00:00',
        ]);
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn(['structured' => null, 'isError' => false, 'text' => json_encode([
                'metadata' => ['type' => 'page'],
                'text' => '<page url="https://app.notion.com/p/'.self::CREATED_ID.'" deleted>'
                    .'<blank-page>This page is blank and has no content.</blank-page></page>',
            ])]);

        $result = app(NotionInvestigationService::class)->refresh($incident);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('deleted', $result['message']);
        $this->assertStringContainsString('Re-investigate', $result['message']);

        $fresh = $incident->fresh();
        $this->assertSame('old body', $fresh->notion_investigation_markdown);
        $this->assertNotNull($fresh->notion_investigation_deleted_at, 'the deleted fetch must flag the record');
    }

    /** A page that came back in Notion (restored by hand) refreshes normally and clears the flag. */
    public function test_a_successful_refresh_clears_the_deleted_flag(): void
    {
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'old body',
            'notion_investigation_synced_at' => '2026-01-01 00:00:00',
            'notion_investigation_deleted_at' => '2026-11-04 00:00:00',
        ]);
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->pageFetchResponse('restored body'));

        $result = app(NotionInvestigationService::class)->refresh($incident);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('restored body', $incident->fresh()->notion_investigation_markdown);
        $this->assertNull($incident->fresh()->notion_investigation_deleted_at);
    }

    /** Round 9: Re-investigate — one button from a flagged record to a fresh page. */
    public function test_reinvestigate_fails_without_a_linked_page(): void
    {
        $result = app(NotionInvestigationService::class)->reinvestigate($this->incident());

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('No investigation page', $result['message']);
    }

    public function test_reinvestigate_fails_when_the_page_is_not_flagged_deleted(): void
    {
        // No client expectations: the guard fires before the client is touched.
        $incident = $this->incident(['notion_investigation_page_id' => self::CREATED_ID]);

        $result = app(NotionInvestigationService::class)->reinvestigate($incident);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('not detected as deleted', $result['message']);
        $this->assertSame(self::CREATED_ID, $incident->fresh()->notion_investigation_page_id, 'the link must survive a refused re-investigation');
    }

    public function test_reinvestigate_fails_for_an_issue_and_keeps_the_link(): void
    {
        // No client expectations: the guard fires before the client is touched.
        $issue = $this->incident([
            'classification' => IncidentClassification::Issue,
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_deleted_at' => '2026-11-04 00:00:00',
        ]);

        $result = app(NotionInvestigationService::class)->reinvestigate($issue);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Only incidents', $result['message']);
        $this->assertSame(self::CREATED_ID, $issue->fresh()->notion_investigation_page_id, 'the link must survive a refused re-investigation');
    }

    /** Restore-from-trash is impossible on the MCP server (live-probed 2026-11-04) — re-investigating unlinks, then starts a fresh page. */
    public function test_reinvestigate_unlinks_and_starts_a_fresh_page(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, self::PARENT_ID);
        $incident = $this->incident([
            'notion_investigation_page_id' => self::CREATED_ID,
            'notion_investigation_markdown' => 'old body',
            'notion_investigation_deleted_at' => '2026-11-04 00:00:00',
            'incident_status' => IncidentStatus::InProgress->value,
        ]);
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::PARENT_ID])
            ->andReturn($this->pageParentResponse())
            ->shouldReceive('callToolDetailed')->once()->with('notion-create-pages', Mockery::any())
            ->andReturn(['structured' => ['id' => self::NEW_ID], 'text' => 'Created page.', 'isError' => false]);

        $result = app(NotionInvestigationService::class)->reinvestigate($incident, User::factory()->create());

        $this->assertSame('ok', $result['status']);

        $fresh = $incident->fresh();
        $this->assertSame(self::NEW_ID, $fresh->notion_investigation_page_id);
        $this->assertNull($fresh->notion_investigation_deleted_at);
        $this->assertStringContainsString($incident->no, (string) $fresh->notion_investigation_markdown);
        $this->assertSame(IncidentStatus::InProgress->value, $fresh->incident_status->value);
    }

    /** The body can still be loading when start finishes — the message says to Refresh. */
    public function test_start_notes_when_the_body_is_still_loading(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, 'collection://'.self::DS_ID);
        $incident = $this->incidentWithDates();

        $client = $this->mock(NotionMcpClient::class);
        $service = new NotionInvestigationService($client, 0);

        $client->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::DS_ID])
            ->andReturn($this->dataSourceSchemaResponse())
            ->shouldReceive('callToolDetailed')->once()->with('notion-create-pages', Mockery::any())
            ->andReturn(['structured' => null, 'text' => '{"pages":[{"id":"'.self::CREATED_ID.'"}]}', 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-update-page', Mockery::any())
            ->andReturn(['structured' => null, 'text' => '{}', 'isError' => false])
            ->shouldReceive('callToolDetailed')->times(3)->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->blankPageFetchResponse());

        $result = $service->start($incident);

        $this->assertSame('ok', $result['status']);
        $this->assertStringContainsString('Refresh from Notion', $result['message']);
    }

    public function test_page_url_points_at_the_stored_page(): void
    {
        $this->assertSame(
            'https://www.notion.so/'.self::CREATED_ID,
            app(NotionInvestigationService::class)->pageUrl(self::CREATED_ID)
        );
    }

    /**
     * The plain-page start flow: connected → parent fetch (typed as a page —
     * that's how the service detects a non-database parent) → create-pages
     * with the incident's `no` in the page title.
     */
    private function mockConnectedCreate(string $titleMustContain, array $response): void
    {
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::PARENT_ID])
            ->andReturn($this->pageParentResponse())
            ->shouldReceive('callToolDetailed')->once()->with('notion-create-pages', Mockery::on(
                function (array $args) use ($titleMustContain) {
                    $page = $args['pages'][0] ?? null;

                    return ($args['parent']['page_id'] ?? null) === self::PARENT_ID
                        && is_array($page)
                        && str_contains((string) ($page['properties']['title'] ?? ''), $titleMustContain);
                }
            ))
            ->andReturn($response);
    }

    /**
     * The data-source start flow: schema fetch → create-pages (asserted by
     * `$ onPage`) → apply_template → body fetch.
     *
     * @param  ?array<string, array{type: string, name: string}>  $schema
     */
    private function mockDataSourceCreate(Incident $incident, callable $onPage, ?array $schema = null, ?string $parentId = null): void
    {
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => $parentId ?? self::DS_ID])
            ->andReturn($this->dataSourceSchemaResponse(schema: $schema))
            ->shouldReceive('callToolDetailed')->once()->with('notion-create-pages', Mockery::on(
                function (array $args) use ($onPage) {
                    $parent = $args['parent'] ?? [];
                    $page = $args['pages'][0] ?? [];

                    $this->assertArrayHasKey('Name', $page['properties'] ?? []);

                    $onPage($page, $parent);

                    return true;
                }
            ))
            ->andReturn(['structured' => null, 'text' => '{"parent":"'.self::DS_ID.'"} {"pages":[{"id":"'.self::CREATED_ID.'","url":"https://app.notion.com/p/'.self::CREATED_ID.'?pvs=204"}]}', 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-update-page', Mockery::on(
                fn (array $args) => ($args['page_id'] ?? null) === self::CREATED_ID
                    && ($args['command'] ?? null) === 'apply_template'
                    && ($args['template_id'] ?? null) === self::TEMPLATE_ID_DASHED
            ))
            ->andReturn(['structured' => null, 'text' => '{"page_id":"..."}', 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => self::CREATED_ID])
            ->andReturn($this->pageFetchResponse('# Template body'));
    }

    /** A notion-fetch response for a plain page parent (no data-source-state). */
    private function pageParentResponse(): array
    {
        return ['structured' => null, 'isError' => false, 'text' => json_encode([
            'metadata' => ['type' => 'page'],
            'text' => '<page url="https://app.notion.com/p/'.self::PARENT_ID.'">'
                .'<content>Team pages live here.</content></page>',
        ])];
    }

    /** The factory randomizes classification — every record here is pinned so the incident-only guard can't flake. */
    private function incident(array $overrides = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'classification' => IncidentClassification::Incident,
        ], $overrides));
    }

    private function incidentWithDates(): Incident
    {
        return $this->incident([
            'incident_date' => '2026-11-01',
            'entry_date_tech_risk' => '2026-10-28',
        ]);
    }

    /**
     * A notion-fetch response for a database, shaped like the live one: the
     * embedded <data-source-state> JSON carries the schema and the DB's
     * default template; `$schema` overrides the default property set.
     *
     * @param  ?array<string, array{type: string, name: string}>  $schema
     */
    private function dataSourceSchemaResponse(string $titleName = 'Name', ?array $schema = null): array
    {
        $state = json_encode([
            'name' => 'Incident Investigation Master',
            'url' => 'collection://'.self::DS_ID_DASHED,
            'default_page_template' => 'https://app.notion.com/p/'.self::TEMPLATE_ID,
            'schema' => $schema ?? [
                $titleName => ['type' => 'title', 'name' => $titleName],
                'Entry Date' => ['type' => 'date', 'name' => 'Entry Date'],
                'Incident Date' => ['type' => 'date', 'name' => 'Incident Date'],
                'PIC' => ['type' => 'person', 'name' => 'PIC'],
                // Options as the live schema carries them (2026-11-04).
                'Incident Category' => ['type' => 'select', 'name' => 'Incident Category', 'options' => array_map(
                    fn (string $name) => ['name' => $name],
                    ['N', 'P0', 'P1', 'P2', 'P3', 'P4', 'X3'],
                )],
                'Fund Loss' => ['type' => 'select', 'name' => 'Fund Loss', 'options' => [['name' => 'No'], ['name' => 'Yes']]],
            ],
        ]);

        return ['structured' => null, 'isError' => false, 'text' => json_encode([
            'metadata' => ['type' => 'database'],
            'text' => '<database url="{{https://app.notion.com/p/3982556e44dd80bc985efaafea444855}}">'
                .'<data-source-state>'.$state.'</data-source-state></database>',
        ])];
    }

    /** A parent stored as the database's own ID routes to the data source the state names. */
    public function test_start_sends_the_data_source_id_when_the_parent_is_a_database_id(): void
    {
        Setting::set(NotionInvestigationService::PARENT_KEY, self::DB_ID);
        $incident = $this->incidentWithDates();
        $this->mockDataSourceCreate($incident, function (array $page, array $parent) {
            $this->assertSame(self::DS_ID_DASHED, $parent['data_source_id']);
            $this->assertArrayNotHasKey('page_id', $parent);
        }, parentId: self::DB_ID);

        $result = app(NotionInvestigationService::class)->start($incident);

        $this->assertSame('ok', $result['status']);
        // The created-page parser must not mistake the echoed data source
        // ID for the created page.
        $this->assertSame(self::CREATED_ID, $incident->fresh()->notion_investigation_page_id);
    }

    /** A notion-fetch response for a page whose body is `$content`. */
    private function pageFetchResponse(string $content): array
    {
        return ['structured' => null, 'isError' => false, 'text' => json_encode([
            'metadata' => ['type' => 'page'],
            'text' => "Here is the result of \"fetch\" for the Page as of 2026-11-03T13:39:48.489Z:\n"
                .'<page url="https://app.notion.com/p/'.self::CREATED_ID.'">'
                .'<ancestor-path><parent-data-source url="collection://'.self::DS_ID.'" name="Incident Investigation Master"/></ancestor-path>'
                .'<properties>{}</properties>'
                .'<content>'.$content.'</content>'
                .'</page>',
        ])];
    }

    /** A notion-fetch response for an empty page (template not landed yet). */
    private function blankPageFetchResponse(): array
    {
        return ['structured' => null, 'isError' => false, 'text' => json_encode([
            'metadata' => ['type' => 'page'],
            'text' => '<page url="https://app.notion.com/p/'.self::CREATED_ID.'">'
                .'<blank-page>This page is blank and has no content.</blank-page></page>',
        ])];
    }

    /**
     * A notion-fetch response for an AI block's inner page: content
     * `$content`, or a blank page when the block holds nothing.
     */
    private function blockFetchResponse(string $blockId, ?string $content): array
    {
        return ['structured' => null, 'isError' => false, 'text' => json_encode([
            'metadata' => ['type' => 'block'],
            'title' => 'Untitled',
            'url' => 'https://app.notion.com/p/'.$blockId.'?pvs=204',
            'text' => $content === null
                ? '<page url="https://app.notion.com/p/'.$blockId.'">'
                    .'<ancestor-path><parent-page url="https://app.notion.com/p/'.self::CREATED_ID.'" title="Invitation Email"/></ancestor-path>'
                    .'<properties>{"title":""}</properties>'
                    .'<blank-page>This page is blank and has no content.</blank-page></page>'
                : '<page url="https://app.notion.com/p/'.$blockId.'">'
                    .'<ancestor-path><parent-page url="https://app.notion.com/p/'.self::CREATED_ID.'" title="Invitation Email"/></ancestor-path>'
                    .'<properties>{"title":""}</properties>'
                    .'<content>'.$content.'</content></page>',
        ])];
    }

    /**
     * A notion-get-users response: `[$id, $name, $email]` triplets.
     *
     * @param  list<array{0: string, 1: string, 2: string}>|list<string>  $users
     */
    private function userSearchResponse(array ...$users): string
    {
        return json_encode([
            'results' => array_map(fn (array $u) => [
                'type' => 'person',
                'id' => $u[0],
                'name' => $u[1],
                'email' => $u[2],
            ], $users),
            'has_more' => false,
        ]);
    }
}
