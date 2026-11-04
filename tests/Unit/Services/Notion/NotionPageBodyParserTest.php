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
}
