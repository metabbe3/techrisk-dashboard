<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Notion;

use App\Services\Notion\NotionBody;
use PHPUnit\Framework\TestCase;

/**
 * PROJ-018 round 7: Notion's fetch envelope renders blocks as unknown HTML
 * tags. Filament runs every markdown entry through Symfony's HtmlSanitizer
 * (safe elements only), which drops unknown tags WITH their content — the
 * body would render as just its first heading. The translation to standard
 * HTML must keep everything visible.
 */
class NotionBodyTest extends TestCase
{
    /**
     * Round 13 (owner: the toggle vertically centered on the title's line,
     * colored, working in light AND dark): the native marker is replaced by
     * an indigo triangle that points down when the section is open (the
     * `group-open:` variant on the details' `group` class).
     *
     * Round 14: the triangle lives INSIDE the heading as an inline element
     * with `vertical-align: middle` — centered against the TEXT itself
     * (baseline/x-height), so it stays centered at every heading size;
     * flex-centering rode the line box (incl. descender space) and sat
     * visibly low on big headings (owner: "should be vertically centered").
     *
     * The `!` classes are NOT decoration: Filament ships an UNLAYERED
     * Tailwind 3 preflight (`* {border-width:0;…}` — unlayered styles beat
     * the app build's @layer utilities at any specificity); the colliding
     * border classes must be important or the marker renders as nothing.
     */
    private const TRAITLET = '<span class="mr-1.5 inline-block h-0! w-0! border-y-4! border-y-transparent! border-l-[6px]! border-l-indigo-500! align-middle transition-transform group-open:rotate-90"></span>';

    public function test_toggles_become_collapsible_sections_with_their_children(): void
    {
        $body = "# Table of Content\n\n<table-of-contents color=\"gray\" />\n\n---\n\n"
            ."<toggle heading=\"h1\">\nPIC\n\n<table><tr><td>Team</td></tr></table>\n</toggle>\n\n"
            .'<toggle heading="h1">'."\nData/Evidence\n".'</toggle>';

        $html = NotionBody::toHtml($body);

        // Owner 2026-11-04: each Notion heading toggles hide/unhide in the
        // portal — <details open>/<summary> (expanded by default so nothing
        // looks missing, click the summary to collapse). Round 11: wider
        // section separation (my-5). Round 12: each section carries an id
        // (the TOC links to it) and the title is a REAL heading — the
        // portal's prose styles (the RCA section's look) supply typography.
        // Round 13 (owner): the body content stays selectable/copyable (no
        // select-none), the marker sits on the title's line and VERTICALLY
        // CENTERED (flex summary), and the traitlet is COLORED (indigo —
        // reads on both light and dark).
        $this->assertStringContainsString('<details id="notion-sec-1" class="group my-5" open><summary class="flex! cursor-pointer"><h1>'.self::TRAITLET.'PIC</h1></summary>', $html);
        $this->assertStringContainsString('<table><tr><td>Team</td></tr></table>', $html);
        $this->assertStringContainsString('<summary class="flex! cursor-pointer"><h1>'.self::TRAITLET.'Data/Evidence</h1></summary>', $html);
        $this->assertStringNotContainsString('select-none', $html);
        $this->assertStringNotContainsString('<toggle', $html);
        $this->assertStringNotContainsString('</toggle>', $html);
        $this->assertStringNotContainsString('<table-of-contents', $html);
    }

    /**
     * Round 9: the summary reflects the Notion heading level. Round 12
     * (owner: "sesuaikan font, style, seperti bagian lain, seperti pada
     * Root Cause Analysis"): the typography comes from the portal's own
     * prose styles — the summary carries a REAL heading tag (h1–h6) and
     * only the indent ladder stays as a class, so the sections read
     * exactly like the headings everywhere else in the infolist.
     */
    public function test_summary_headings_follow_the_notion_heading_level(): void
    {
        $body = "<toggle heading=\"h1\">\nOne\n\nx\n</toggle>\n\n"
            ."<toggle heading=\"h2\">\nTwo\n\nx\n</toggle>\n\n"
            ."<toggle heading=\"h3\">\nThree\n\nx\n</toggle>\n\n"
            ."<toggle heading=\"h4\">\nFour\n\nx\n</toggle>\n\n"
            ."<toggle heading=\"h5\">\nFive\n\nx\n</toggle>\n\n"
            ."<toggle heading=\"h6\">\nSix\n\nx\n</toggle>";

        $html = NotionBody::toHtml($body);

        $s = '<summary class="flex! cursor-pointer"><';
        $this->assertStringContainsString($s.'h1>'.self::TRAITLET.'One</h1></summary>', $html);
        $this->assertStringContainsString($s.'h2 class="pl-4">'.self::TRAITLET.'Two</h2></summary>', $html);
        $this->assertStringContainsString($s.'h3 class="pl-8">'.self::TRAITLET.'Three</h3></summary>', $html);
        $this->assertStringContainsString($s.'h4 class="pl-12">'.self::TRAITLET.'Four</h4></summary>', $html);
        $this->assertStringContainsString($s.'h5 class="pl-12">'.self::TRAITLET.'Five</h5></summary>', $html);
        $this->assertStringContainsString($s.'h6 class="pl-12">'.self::TRAITLET.'Six</h6></summary>', $html);
    }

    public function test_nested_toggles_become_collapsible_sections_at_each_level(): void
    {
        $body = "<toggle heading=\"h1\">\nMOM\n\n<toggle heading=\"h2\">\nGenerator\n\n<p>ai block</p>\n</toggle>\n</toggle>";

        $html = NotionBody::toHtml($body);

        $this->assertStringContainsString('<summary class="flex! cursor-pointer"><h1>'.self::TRAITLET.'MOM</h1></summary>', $html);
        $this->assertStringContainsString('<summary class="flex! cursor-pointer"><h2 class="pl-4">'.self::TRAITLET.'Generator</h2></summary>', $html);
        $this->assertStringContainsString('<p>ai block</p>', $html);
        $this->assertStringNotContainsString('<toggle', $html);
        $this->assertStringNotContainsString('</toggle>', $html);
    }

    public function test_notion_ui_wrappers_unwrap_and_widgets_drop(): void
    {
        $body = "Meeting <date start=\"2026-11-04\" />\n\n"
            ."<meeting-notes view-url=\"https://app.notion.com/x\">\n<p>notes here</p>\n</meeting-notes>\n\n"
            .'<unknown url="https://app.notion.com/y" alt="some_other_block" />'."\n\nafter";

        $html = NotionBody::toHtml($body);

        $this->assertStringContainsString('Meeting', $html);
        $this->assertStringContainsString('<p>notes here</p>', $html);
        $this->assertStringContainsString('after', $html);
        $this->assertStringNotContainsString('<date', $html);
        $this->assertStringNotContainsString('meeting-notes', $html);
        // Round 10: only an ai_block placeholder renders as a note — every
        // other Notion widget still unwraps around its content.
        $this->assertStringNotContainsString('<unknown', $html);
    }

    /**
     * Round 10 ("table content not fully imported"): a kept (un-generated)
     * ai_block placeholder renders as a visible note — unwrapping it to
     * nothing showed empty sections where Notion has AI blocks waiting to
     * be run (RCA / MOM / Invitation — the blocks that later carry the
     * table-heavy generated content).
     */
    public function test_an_ungenerated_ai_block_placeholder_renders_as_a_note(): void
    {
        $body = '<toggle heading="h1">'."\nRCA\n\n"
                .'<unknown url="https://app.notion.com/p/abc123#3ff2556e44dd806dbbbdd21c5c7d7f2e" alt="ai_block" />'
                ."\n</toggle>";

        $html = NotionBody::toHtml($body);

        $this->assertStringContainsString(
            '<p class="text-sm text-gray-500 italic">AI block — not generated in Notion yet. Run it there, then Refresh.</p>',
            $html
        );
        $this->assertStringNotContainsString('<unknown', $html);
    }

    /**
     * Round 10 (probe 2026-11-04): apply_template DOES copy the template's
     * file attachments — but a `<file source="notion-file-block://…">` tag
     * is not renderable in the portal (the scheme is MCP-internal and the
     * sanitizer drops the unknown element). Render the attachment by name.
     */
    public function test_a_file_attachment_renders_as_a_named_note(): void
    {
        $body = '<toggle heading="h1">'."\nInvestigation\n\n"
                .'<file source="notion-file-block://41b2556e-44dd-823c-92f6-012f39275960/4124833a-4576-4dd6-a842-1605ecbe50c1?space_id=1db670e5-4276-49eb-92b3-c9a7127b677e&name=investigation_plan_instructions.md" />'
                ."\n</toggle>";

        $html = NotionBody::toHtml($body);

        $this->assertStringContainsString(
            '<p class="text-sm text-gray-500">Attachment (in Notion): investigation_plan_instructions.md</p>',
            $html
        );
        $this->assertStringNotContainsString('<file', $html);
    }

    /**
     * Round 11 (owner: "can you extract the table of content?"): the
     * `<table-of-contents>` widget is generated BY Notion from the page's
     * headings (never typed) — the envelope carries only the self-closing
     * tag, so the portal REBUILDS the list from those same headings
     (toggle titles + bare markdown headings) at the tag's position.
     * Round 12 (owner): the entries LINK to their sections — each toggle
     * gets a positional id and the entry an anchor to it (bare markdown
     * headings have no section to link and stay plain text).
     */
    public function test_the_table_of_contents_widget_renders_the_pages_headings(): void
    {
        $body = "# Table of Content\n\n<table-of-contents color=\"gray\" />\n\n---\n\n"
            ."<toggle heading=\"h1\">\nPIC\n\n<table><tr><td>Team</td></tr></table>\n</toggle>\n\n"
            ."<toggle heading=\"h2\">\nGenerator\n\nx\n</toggle>\n\n"
            ."<toggle heading=\"h3\">\nTimeline\n\nx\n</toggle>\n\n"
            ."## Notes\n\nbare heading section";

        $html = NotionBody::toHtml($body);

        $this->assertStringNotContainsString('<table-of-contents', $html);
        // The TOC's own heading never lists itself.
        $this->assertStringNotContainsString('<li>Table of Content</li>', $html);
        // Document order, indented by level (h1 flush, h2 pl-4, h3 pl-8),
        // toggles linked to their section ids.
        $this->assertStringContainsString('<ul class="list-none! space-y-1 p-0!"><li><a href="#notion-sec-1">PIC</a></li>'
            ."\n".'<li class="pl-4"><a href="#notion-sec-2">Generator</a></li>'
            ."\n".'<li class="pl-8"><a href="#notion-sec-3">Timeline</a></li>'
            ."\n".'<li class="pl-4">Notes</li></ul>', $html);
        // The ids the entries point at sit on the sections themselves.
        $this->assertStringContainsString('<details id="notion-sec-1" class="group my-5" open><summary class="flex! cursor-pointer"><h1>'.self::TRAITLET.'PIC</h1></summary>', $html);
        $this->assertStringContainsString('<details id="notion-sec-3" class="group my-5" open><summary class="flex! cursor-pointer"><h3 class="pl-8">'.self::TRAITLET.'Timeline</h3></summary>', $html);
    }

    /** No headings to list → the widget drops and nothing is written (owner: "if can't, don't write the table of content section"). */
    public function test_a_table_of_contents_with_no_headings_writes_nothing(): void
    {
        $body = "# Table of Content\n\n<table-of-contents color=\"gray\" />\n\nJust text.";

        $html = NotionBody::toHtml($body);

        $this->assertStringNotContainsString('<table-of-contents', $html);
        $this->assertStringNotContainsString('<ul', $html);
        $this->assertStringContainsString('Just text.', $html);
    }

    public function test_plain_markdown_passes_through_untouched(): void
    {
        $body = '# Title\n\nSome **bold** text.';

        $this->assertSame($body, NotionBody::toHtml($body));
    }
}
