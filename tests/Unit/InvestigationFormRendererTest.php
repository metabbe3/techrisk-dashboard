<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\InvestigationForm\InvestigationFormRenderer;
use App\Services\InvestigationForm\InvestigationFormSchema;
use App\Services\InvestigationForm\InvestigationFormSettings;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Renderer contract: the DOCX is the owner template with every ${token}
 * replaced (incl. cloned chronology/action rows), the PDF is a real %PDF,
 * and an empty draft leaves no ${...} behind.
 */
class InvestigationFormRendererTest extends TestCase
{
    private function draft(): array
    {
        $qa = fn (int $n, string $v): array => array_fill_keys(
            array_map(fn (int $i): string => "q{$i}", range(1, $n)),
            $v,
        );

        return [
            'header' => [
                'incident_name' => 'Test & <fail>', 'business_unit' => 'BU', 'technology' => 'Tech',
                'incident_date' => '2026-11-01T10:00', 'name' => 'Alice', 'impacted_domain' => 'd',
                'job_role' => 'r', 'duration' => '1h', 'job_function' => 'f', 'statement_date' => '2026-11-16T09:00',
            ],
            'summary' => [
                'description' => 'Desc', 'business_flow' => 'Flow', 'business_impact' => 'Impact',
                'occurred_time' => '2026-11-01T10:00', 'discovery_time' => '2026-11-01T10:20', 'discovery_mechanism' => 'monitoring',
                'stop_bleeding_time' => '2026-11-01T10:40',
            ],
            'development_process' => $qa(6, 'a'),
            'testing_process' => $qa(8, 'ta'),
            'deployment_process' => $qa(4, 'da'),
            'fund_check_monitoring' => $qa(7, 'fa'),
            'contingency_process' => $qa(5, 'ca'),
            'internal_control' => $qa(3, 'ia'),
            'grc_review' => $qa(3, 'ga'),
            'chronology' => [
                ['time' => '2026-11-01T10:00', 'event' => 'Alert fired'],
                ['time' => '2026-11-01T10:15', 'event' => 'Mitigated'],
            ],
            'action_items' => [
                ['item' => 'Add alert', 'due_date' => '2026-11-30T17:00', 'verification' => 'Alert exists', 'owner' => 'Alice'],
                ['item' => 'Write RCA', 'due_date' => '', 'verification' => '', 'owner' => 'Bob'],
            ],
            'submitter_name' => 'Alice',
        ];
    }

    private function docxXml(array $draft): string
    {
        $bytes = app(InvestigationFormRenderer::class)->docx($draft);
        $path = tempnam(sys_get_temp_dir(), 'invtest-').'.docx';
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);

        return $xml;
    }

    public function test_docx_contains_values_and_no_leftover_tokens(): void
    {
        $xml = $this->docxXml($this->draft());

        $this->assertSame(0, substr_count($xml, '${'), 'leftover ${...} tokens in the rendered DOCX');
        $this->assertStringContainsString('Test &amp; &lt;fail&gt;', $xml, 'values are XML-escaped');
        $this->assertStringContainsString('Alert fired', $xml);
        $this->assertStringContainsString('Mitigated', $xml, 'second chronology row must exist (cloneRow)');
        $this->assertStringContainsString('Add alert', $xml);
        $this->assertStringContainsString('Alice', $xml, 'submitter name');
    }

    public function test_datetime_values_print_as_space_separated_and_empty_due_date_prints_tbd(): void
    {
        $xml = $this->docxXml($this->draft());

        $this->assertStringContainsString('2026-11-01 10:00', $xml, 'picker values print without the T');
        $this->assertStringNotContainsString('T10:00', $xml);
        $this->assertStringContainsString('TBD', $xml, 'an action row with no due date prints TBD');
    }

    public function test_docx_with_empty_lists_leaves_no_tokens(): void
    {
        $draft = $this->draft();
        $draft['chronology'] = [];
        $draft['action_items'] = [];

        $this->assertSame(0, substr_count($this->docxXml($draft), '${'));
    }

    public function test_pdf_starts_with_pdf_magic(): void
    {
        $pdf = app(InvestigationFormRenderer::class)->pdf($this->draft());

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_settings_render_escapes_values_and_keeps_unknown_tokens(): void
    {
        $out = InvestigationFormSettings::render('Hi {incident_title} <b>{link}</b> {not_a_placeholder}', [
            'incident_title' => 'Title <x>',
            'link' => 'https://x.test/l',
        ]);

        $this->assertSame('Hi Title &lt;x&gt; <b>https://x.test/l</b> {not_a_placeholder}', $out);
    }

    public function test_settings_unknown_placeholders_detected(): void
    {
        $this->assertSame(['bogus'], InvestigationFormSettings::unknownPlaceholders('x {bogus} y {link}', false));
        $this->assertSame([], InvestigationFormSettings::unknownPlaceholders('x {days_left} y {link}', true));
        $this->assertSame(['days_left'], InvestigationFormSettings::unknownPlaceholders('x {days_left}', false), 'days_left is reminder-only');
    }

    public function test_required_fields_default_covers_header_summary_and_chronology(): void
    {
        $required = InvestigationFormSettings::requiredFields();

        $this->assertContains('header.incident_name', $required);
        $this->assertContains('summary.description', $required);
        $this->assertContains('chronology', $required);
        $this->assertNotContains('testing_process.q1', $required, 'Q&A sections are optional by default');
        $this->assertNotContains('action_items', $required);
    }

    public function test_ai_brain_sends_the_big_reply_cap_for_extraction_only(): void
    {
        config()->set('ai.go_agent.token', 'secret');
        config()->set('ai.go_agent.base_url', 'http://brain.test');

        Http::fake(['brain.test/internal/run' => Http::response(['ok' => true, 'output' => '{"ok":1}'])]);

        app(\App\Services\InvestigationForm\InvestigationFormExtractor::class)->extract('source text');

        Http::assertSentInOrder([
            function ($request): bool {
                $payload = $request->data();

                return ($payload['max_reply_chars'] ?? 0) === 60000
                    && $payload['mode'] === 'task'
                    && ($payload['agent_id'] ?? '') === 'investigation-form-intake';
            },
        ]);

        Http::fake(['brain.test/internal/run' => Http::response(['ok' => true, 'output' => '{"issues":[]}'])]);

        app(\App\Services\InvestigationForm\InvestigationFormExtractor::class)->verify([]);

        Http::assertSentInOrder([
            function ($request): bool {
                $payload = $request->data();

                return ! isset($payload['max_reply_chars'], $payload['agent_id']) || $payload['max_reply_chars'] === null;
            },
        ]);
    }

    public function test_statement_of_truth_matches_the_template(): void
    {
        $lower = mb_strtolower(InvestigationFormSchema::STATEMENT_OF_TRUTH);

        $this->assertStringContainsString('true', $lower);
        $this->assertStringContainsString('legal impact', $lower);
        $this->assertStringContainsString('false statement', $lower);
    }
}
