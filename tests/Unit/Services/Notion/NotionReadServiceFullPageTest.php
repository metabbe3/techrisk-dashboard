<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Notion;

use App\Services\Notion\NotionMcpClient;
use App\Services\Notion\NotionReadService;
use Mockery;
use Tests\TestCase;

/**
 * readFullPage — the intake's "read ALL content" fetch: title + properties
 * (AI summary, dates, PIC, Category, Fund Loss), AI-block inlining, child
 * pages (one level), truncated-subtree drains, image blocks compressed to
 * named notes, and the colgroup noise dropped. The inner child-page tag
 * shape has no repo fixture (live MCP pages only) — the regex is tolerant
 * and a miss passes the tag through raw.
 */
class NotionReadServiceFullPageTest extends TestCase
{
    private const AI_ANCHOR = 'aaa11111-1111-1111-1111-111111111111';

    private const CHILD_ID = 'bbb22222-2222-2222-2222-222222222222';

    private const SUBTREE_ID = 'ccc33333-3333-3333-3333-333333333333';

    private function envelope(string $text, ?array $structured = null): string
    {
        $payload = ['title' => 'Summary of Incident - 02 November 2026', 'text' => $text];

        if ($structured !== null) {
            $payload['structured'] = $structured;
        }

        return json_encode($payload);
    }

    public function test_composes_title_properties_ai_blocks_children_subtrees_and_image_notes(): void
    {
        $page = $this->envelope(
            '<page url="https://app.notion.com/P1" icon="x">'
            .'<properties>{"AI summary":"Root cause: dead DB","Category":"P1","PIC":"Alice"}</properties>'
            .'<content>Body line one.'
            .'<image source="notion-file-block://40c2/d5d7?space_id=x&name=image.png" />'
            .'<file source="notion-file-block://40c2/notes?name=plan.md" />'
            .'<unknown url="https://app.notion.com/p/abc#'.self::AI_ANCHOR.'" alt="ai_block" />'
            .'<page url="https://app.notion.com/'.self::CHILD_ID.'" />'
            .'<table><colgroup><col/></colgroup><tr><td>cell</td></tr></table>'
            .'</content></page>',
        );

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callToolDetailed')->with('notion-fetch', ['id' => 'P1'])
            ->andReturn(['structured' => ['truncated' => true, 'unknown_block_ids' => [self::SUBTREE_ID]], 'text' => $page, 'isError' => false])
            ->shouldReceive('callToolDetailed')->with('notion-fetch', ['id' => str_replace('-', '', self::AI_ANCHOR)])
            ->andReturn(['structured' => null, 'text' => $this->envelope('<content>Generated RCA content.</content>'), 'isError' => false])
            ->shouldReceive('callToolDetailed')->with('notion-fetch', ['id' => self::CHILD_ID])
            ->andReturn(['structured' => null, 'text' => $this->envelope('<content>Child page body.</content>'), 'isError' => false])
            ->shouldReceive('callToolDetailed')->with('notion-fetch', ['id' => self::SUBTREE_ID])
            ->andReturn(['structured' => null, 'text' => $this->envelope('<content>Omitted subtree.</content>'), 'isError' => false]);

        $out = app(NotionReadService::class)->readFullPage('P1');

        $this->assertSame('', $out['error']);
        $this->assertTrue($out['truncated']);
        $this->assertSame(1, $out['images'], 'only <image> counts, not <file>');
        $this->assertStringContainsString('# Summary of Incident - 02 November 2026', $out['body']);
        $this->assertStringContainsString('"AI summary":"Root cause: dead DB"', $out['body']);
        $this->assertStringContainsString('Body line one.', $out['body']);
        $this->assertStringContainsString('Generated RCA content.', $out['body']);
        $this->assertStringContainsString('Child page body.', $out['body']);
        $this->assertStringContainsString('Omitted subtree.', $out['body']);
        $this->assertStringContainsString('[image: image.png]', $out['body']);
        $this->assertStringContainsString('[file: plan.md]', $out['body']);
        $this->assertStringContainsString('cell', $out['body']);
        $this->assertStringNotContainsString('<image source', $out['body']);
        $this->assertStringNotContainsString('<colgroup>', $out['body']);
        $this->assertStringNotContainsString('alt="ai_block"', $out['body']);
        $this->assertStringNotContainsString('<page url', $out['body'], 'child tags are replaced');
    }

    public function test_an_unreadable_page_returns_notions_own_refusal(): void
    {
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callToolDetailed')->with('notion-fetch', ['id' => 'P1'])
            ->andReturn(['structured' => null, 'text' => 'object_not_found', 'isError' => true]);

        $out = app(NotionReadService::class)->readFullPage('P1');

        $this->assertNull($out['body']);
        $this->assertSame('object_not_found', $out['error']);
    }

    public function test_a_blank_page_still_yields_title_and_properties(): void
    {
        $page = $this->envelope(
            '<page url="https://app.notion.com/P1"><properties>{"Category":"P1"}</properties><blank-page>This page is blank.</blank-page></page>',
        );

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callToolDetailed')->with('notion-fetch', ['id' => 'P1'])
            ->andReturn(['structured' => null, 'text' => $page, 'isError' => false]);

        $out = app(NotionReadService::class)->readFullPage('P1');

        $this->assertStringContainsString('# Summary of Incident', $out['body']);
        $this->assertStringContainsString('"Category":"P1"', $out['body']);
        $this->assertFalse($out['truncated']);
    }

    public function test_a_content_block_without_its_close_tag_still_extracts(): void
    {
        // Live 2026-11-04 fixture shape: the envelope's tail is cut —
        // <content> with no </content>. The old both-tags regex returned null.
        $page = $this->envelope('<page url="x"><content>Body with no close tag');

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callToolDetailed')->with('notion-fetch', ['id' => 'P1'])
            ->andReturn(['structured' => null, 'text' => $page, 'isError' => false]);

        $out = app(NotionReadService::class)->readFullPage('P1');

        $this->assertStringContainsString('Body with no close tag', $out['body']);
    }

    public function test_a_failed_child_or_subtree_fetch_never_kills_the_page(): void
    {
        $page = $this->envelope(
            '<content>Body.'
            .'<unknown url="https://app.notion.com/p/abc#'.self::AI_ANCHOR.'" alt="ai_block" />'
            .'<page url="https://app.notion.com/'.self::CHILD_ID.'" />'
            .'</content>',
        );

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callToolDetailed')->with('notion-fetch', ['id' => 'P1'])
            ->andReturn(['structured' => ['truncated' => true, 'unknown_block_ids' => [self::SUBTREE_ID]], 'text' => $page, 'isError' => false])
            ->shouldReceive('callToolDetailed')->with('notion-fetch', Mockery::on(
                fn (array $args) => in_array($args['id'] ?? '', [str_replace('-', '', self::AI_ANCHOR), self::CHILD_ID, self::SUBTREE_ID], true)
            ))
            ->andReturn(['structured' => null, 'text' => 'object_not_found', 'isError' => true]);

        $out = app(NotionReadService::class)->readFullPage('P1');

        $this->assertStringContainsString('Body.', $out['body']);
        $this->assertStringContainsString('alt="ai_block"', $out['body'], 'a failed block keeps its placeholder');
        $this->assertStringContainsString('<page url', $out['body'], 'a failed child keeps its tag');
    }
}
