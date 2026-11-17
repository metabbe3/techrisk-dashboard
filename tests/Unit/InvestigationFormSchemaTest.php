<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\GoAgentBrain;
use App\Services\InvestigationForm\InvestigationFormExtractor;
use App\Services\InvestigationForm\InvestigationFormSchema;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InvestigationFormSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.go_agent.token', 'secret');
        config()->set('ai.go_agent.base_url', 'http://brain.test');
    }

    public function test_schema_sections_match_the_owner_template(): void
    {
        $sections = InvestigationFormSchema::SECTIONS;

        $this->assertSame('Header', $sections['header']['label']);
        $this->assertCount(10, $sections['header']['fields']);
        $this->assertCount(7, $sections['summary']['fields']);
        $this->assertSame(['time', 'event'], array_keys($sections['chronology']['fields']));
        $this->assertCount(6, $sections['development_process']['fields']);
        $this->assertCount(8, $sections['testing_process']['fields']);
        $this->assertCount(4, $sections['deployment_process']['fields']);
        $this->assertCount(7, $sections['fund_check_monitoring']['fields']);
        $this->assertCount(5, $sections['contingency_process']['fields']);
        $this->assertCount(3, $sections['internal_control']['fields']);
        $this->assertCount(3, $sections['grc_review']['fields']);
        $this->assertSame(['item', 'due_date', 'verification', 'owner'], array_keys($sections['action_items']['fields']));
    }

    public function test_statement_of_truth_is_verbatim(): void
    {
        $this->assertStringContainsString('without any alteration or modification', InvestigationFormSchema::STATEMENT_OF_TRUTH);
    }

    public function test_flat_fields_are_prefixed_by_section(): void
    {
        $flat = InvestigationFormSchema::flatFields();

        $this->assertArrayHasKey('header.name', $flat);
        $this->assertArrayHasKey('summary.description', $flat);
        $this->assertArrayHasKey('grc_review.q3', $flat);
        $this->assertSame('Description', $flat['summary.description']);
    }

    public function test_extractor_normalizes_ai_output_and_drops_unknown_keys(): void
    {
        $payload = json_encode([
            'header' => ['name' => 'Budi', 'hacker_key' => 'ignored', 'incident_date' => 12345],
            'summary' => ['description' => '  App error spike  ', 'discovery_time' => ['not' => 'a string']],
            'chronology' => [
                ['time' => '09:45', 'event' => 'Error spike'],
                ['event' => 'no time — dropped'],
                'garbage — dropped',
            ],
            'action_items' => [['item' => 'Add regression case', 'due_date' => '2026-12-01']],
            'made_up_section' => ['q1' => 'dropped'],
            'missing' => [
                ['field' => 'summary.business_flow', 'question' => 'What was the business flow?'],
                ['field' => 'not.a.field', 'question' => 'dropped — unknown key'],
            ],
        ]);

        Http::fake([
            '*/internal/run' => Http::response([
                'ok' => true,
                'output' => $payload,
                'model' => 'glm-test',
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ]),
        ]);

        $out = app(InvestigationFormExtractor::class)->extract('source text');

        $this->assertTrue($out['result']->success);
        $this->assertSame('Budi', $out['fields']['header.name']);
        $this->assertSame('App error spike', $out['fields']['summary.description']);
        $this->assertArrayNotHasKey('header.hacker_key', $out['fields']);
        $this->assertArrayNotHasKey('summary.discovery_time', $out['fields']);
        $this->assertSame([['time' => '09:45', 'event' => 'Error spike']], $out['chronology']);
        $this->assertSame('2026-12-01', $out['action_items'][0]['due_date']);
        $this->assertSame([['field' => 'summary.business_flow', 'question' => 'What was the business flow?']], $out['missing']);
    }

    public function test_extractor_repairs_invalid_json_once(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            // First call returns fenced prose, the repair call returns clean JSON.
            $output = $calls === 1
                ? "Here you go:\n```json\n{\"header\": {\"name\": \"Fixed\"}}\n```"
                : '{"header": {"name": "Fixed"}}';

            return Http::response(['ok' => true, 'output' => $output, 'model' => 'glm-test']);
        });

        $out = app(InvestigationFormExtractor::class)->extract('source');

        $this->assertSame('Fixed', $out['fields']['header.name']);
        $this->assertSame(2, $calls);
    }

    public function test_extractor_transport_failure_returns_failed_result(): void
    {
        config()->set('ai.go_agent.token', '');

        $out = app(InvestigationFormExtractor::class)->extract('source');

        $this->assertFalse($out['result']->success);
        $this->assertSame([], $out['fields']);
        $this->assertSame([], $out['missing']);
    }

    public function test_verify_returns_normalized_issues(): void
    {
        Http::fake([
            '*/internal/run' => Http::response([
                'ok' => true,
                'output' => '{"issues": [{"field": "chronology", "issue": "Row 2 is before row 1"}, {"bad": "shape"}]}',
                'model' => 'glm-test',
            ]),
        ]);

        $out = app(InvestigationFormExtractor::class)->verify(['summary' => ['description' => 'x']]);

        $this->assertTrue($out['result']->success);
        $this->assertCount(1, $out['issues']);
        $this->assertSame('Row 2 is before row 1', $out['issues'][0]['issue']);
    }

    public function test_go_brain_sends_max_reply_cap_for_extraction_only(): void
    {
        Http::fake(['http://brain.test/internal/run' => Http::response(['ok' => true, 'output' => '{}', 'model' => 'm'])]);

        $brain = app(GoAgentBrain::class);
        $brain->runTask('sys', 'msg', GoAgentBrain::FIELD_TYPE_EXTRACT);
        $brain->runTask('sys', 'msg', GoAgentBrain::FIELD_TYPE_VERIFY);

        Http::assertSentInOrder([
            function ($request) {
                $data = $request->data();

                return $data['mode'] === 'task'
                    && $data['max_reply_chars'] === 60000
                    && $request->hasHeader('X-Agent-Token', 'secret');
            },
            function ($request) {
                return ! array_key_exists('max_reply_chars', $request->data());
            },
        ]);
    }
}
