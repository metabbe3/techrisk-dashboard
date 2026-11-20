<?php

namespace Tests\Unit;

use Tests\TestCase;

class SectionHeaderCornerRadiusTest extends TestCase
{
    /**
     * Owner 2026-11-18: the section header band is painted with a background
     * but no radius, so its square corners overlap the card's 12px rounded
     * corners (the card keeps `overflow: visible` so dropdowns escape — the
     * header is not clipped). The header must round its top corners to the
     * card's inner radius (12px outer − 1px border).
     */
    public function test_the_section_header_rounds_its_top_corners_to_match_the_card(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString(
            ".fi-resource-edit-record-page .fi-section-header,\n.fi-resource-create-record-page .fi-section-header {",
            $css,
        );

        $this->assertStringContainsString('border-radius: 11px 11px 0 0;', $css);
    }
}
