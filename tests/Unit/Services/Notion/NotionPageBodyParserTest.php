<?php

namespace Tests\Unit\Services\Notion;

use App\Services\Notion\NotionPageBodyParser;
use PHPUnit\Framework\TestCase;

class NotionPageBodyParserTest extends TestCase
{
    /** The live page shape (2026-11-03 sample, headings restored). */
    protected const SAMPLE_BODY = <<<'MD'
    # Summary of Incident - 02 November 2026 - Multiple Bank Topup & Merchant IPG Issue

    Non Performance Incident | Patrick | November 2, 2026 5:49 PM

    On November 2, 2026, multiple bank top-ups (BCA, BNI, Mandiri, and Permata), merchant IPG services (TikTok, Bytedance, and TikTokShop), and send-money services (Seabank and Midtrans) were disrupted. Root cause: Still under investigation; high memory usage across ifcsupergw instances was observed. When: 15:52–18:01. Total duration: 2 hours 9 minutes. Next actions: Continue investigating through BRIGHT ticket INC503 and A+ ticket 4309552.

    ## Date Time

    02 November 2026, 15:52 - 16:53

    ## Duration

    - Topup (BCA): 15:54 Decrease → 16:15 Recover (21m)
    - Topup (BNI): 15:54 Decrease → 16:15 Recover (21m)

    ## **Root Cause**

    Still Investigating

    ## Service Impact

    Topup (BCA, BNI, Mandiri, Permata)

    ## **Action Taken**

    - SRE gradual restart ifcsupergw
    - TS create ticket to BRIGHT to investigate the ifcsupergw and top-up issue INC503

    ## Incident Timeline and Chronology

    15:52 → Many ifcsupergw instances has high memory usage
    15:54 → Topup (BCA, BNI, Mandiri, Permata) Decrease
    18:01 → Key Account Merchant (TiktokShop) Recover

    ## Attachment
    MD;

    public function test_maps_known_sections_to_their_fields(): void
    {
        $parsed = NotionPageBodyParser::parse(self::SAMPLE_BODY);

        $this->assertSame('Still Investigating', $parsed['root_cause']);
        $this->assertStringContainsString('SRE gradual restart ifcsupergw', $parsed['improvements']);
        $this->assertStringNotContainsString('SRE gradual restart', $parsed['summary']);
        $this->assertStringContainsString('15:52 → Many ifcsupergw instances has high memory usage', $parsed['timeline']);
        $this->assertStringContainsString('18:01 → Key Account Merchant (TiktokShop) Recover', $parsed['timeline']);
    }

    public function test_unknown_sections_stay_in_summary_with_their_headings(): void
    {
        $parsed = NotionPageBodyParser::parse(self::SAMPLE_BODY);

        // Leading incident summary paragraph.
        $this->assertStringContainsString('multiple bank top-ups (BCA, BNI, Mandiri, and Permata)', $parsed['summary']);
        // Unmapped sections stay, labelled by their own heading lines.
        $this->assertStringContainsString('Date Time', $parsed['summary']);
        $this->assertStringContainsString('02 November 2026, 15:52 - 16:53', $parsed['summary']);
        $this->assertStringContainsString('Service Impact', $parsed['summary']);
        // Known sections' content must not leak into summary.
        $this->assertStringNotContainsString('Still Investigating', $parsed['summary']);
    }

    public function test_plain_and_colon_heading_styles_match(): void
    {
        $body = "Intro text\nRoot Cause:\nthe cause\nAction Taken\n- did a thing";

        $parsed = NotionPageBodyParser::parse($body);

        $this->assertSame('the cause', $parsed['root_cause']);
        $this->assertSame('- did a thing', $parsed['improvements']);
        $this->assertSame('Intro text', $parsed['summary']);
    }

    public function test_body_without_any_heading_lands_whole_in_summary(): void
    {
        $parsed = NotionPageBodyParser::parse("Just prose.\nMore prose.");

        $this->assertSame("Just prose.\nMore prose.", $parsed['summary']);
        $this->assertSame('', $parsed['root_cause']);
        $this->assertSame('', $parsed['timeline']);
        $this->assertSame('', $parsed['improvements']);
    }

    public function test_empty_body_returns_empty_fields(): void
    {
        $parsed = NotionPageBodyParser::parse('');

        $this->assertSame(['summary' => '', 'root_cause' => '', 'timeline' => '', 'improvements' => ''], $parsed);
    }

    /** The raw notion-fetch envelope (live sample 2026-11-03): preamble, page wrapper, image blocks, schema trailer. */
    protected const ENVELOPE_BODY = <<<'TXT'
    Here is the result of "fetch" for the Page with URL https://app.notion.com/p/40c2556e44dd81afa4e5ed13b6171933 as of 2026-11-03T03:41:16.885Z:
    <page url="https://app.notion.com/p/40c2556e44dd81afa4e5ed13b6171933">



    <image source="notion-file-block://40c2556e-44dd-80fd-b0d1-dffb4a3d1d97/d5d765c0-14e1-4248-a2d2-8c68d29e7260?space_id=1db670e5-4276-49eb-92b3-c9a7127b677e&name=image.png" />



    <image source="notion-file-block://40c2556e-44dd-808a-8d47-cce85495f8ba/8258527a-3a6a-4139-9adf-f589641954c2?space_id=1db670e5-4276-49eb-92b3-c9a7127b677e&name=image.png" />

    BKE Pending

    <image source="notion-file-block://40c2556e-44dd-80f8-99cc-e177123d878c/441dea3f-d82e-4f11-a1de-e4e08d25cea8?space_id=1db670e5-4276-49eb-92b3-c9a7127b677e&name=image.png" />



    Top up:

    <image source="notion-file-block://40c2556e-44dd-803f-bf48-f4cf67097ab7/5eb2a1b8-300d-4a4e-a3aa-a5bf68290f74?space_id=1db670e5-4276-49eb-92b3-c9a7127b677e&name=image.png" />

    <parent-data-source-schema url="collection://2c72556e-44dd-81cf-8977-000bc43d6f86">
    The SQLite table definition for this page's parent data source. Use these column names and option values to set this page's properties, create sibling pages, or query the data source.
    TXT;

    public function test_strips_the_fetch_envelope_from_the_summary(): void
    {
        $parsed = NotionPageBodyParser::parse(self::ENVELOPE_BODY);

        // The real page text survives, as [image] placeholders for its images.
        $this->assertStringContainsString('BKE Pending', $parsed['summary']);
        $this->assertStringContainsString('Top up:', $parsed['summary']);
        $this->assertStringContainsString('[image]', $parsed['summary']);

        // None of the tool envelope leaks in (schema trailer is cut off
        // mid-block in the live sample — no close tag).
        foreach (['notion-file-block', 'parent-data-source-schema', 'collection://', 'Here is the result', 'SQLite table definition'] as $junk) {
            $this->assertStringNotContainsString($junk, $parsed['summary']);
        }
    }

    public function test_plain_headings_inside_the_envelope_still_route(): void
    {
        $body = 'Here is the result of "fetch" for the Page with URL https://app.notion.com/p/abc as of 2026-11-03T03:41:16.885Z:'."\n"
            .'<page url="https://app.notion.com/p/abc">'."\n\n"
            ."On November 2, 2026, top-ups were disrupted.\n\n"
            ."Root Cause\n\nStill Investigating\n\n"
            ."Action Taken\n\n- SRE gradual restart ifcsupergw\n\n"
            ."Incident Timeline and Chronology\n\n15:52 → Many ifcsupergw instances has high memory usage\n\n"
            .'<parent-data-source-schema url="collection://2c72556e-44dd-81cf-8977-000bc43d6f86">'."\n"
            ."The SQLite table definition for this page's parent data source.";

        $parsed = NotionPageBodyParser::parse($body);

        $this->assertSame('Still Investigating', $parsed['root_cause']);
        $this->assertStringContainsString('SRE gradual restart ifcsupergw', $parsed['improvements']);
        $this->assertStringContainsString('15:52 → Many ifcsupergw instances', $parsed['timeline']);
        $this->assertStringContainsString('top-ups were disrupted', $parsed['summary']);
        $this->assertStringNotContainsString('SQLite table definition', $parsed['summary']);
    }
}
