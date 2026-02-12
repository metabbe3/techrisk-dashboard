<?php

namespace Tests\Unit\Helpers;

use App\Helpers\StringHelper;
use Tests\TestCase;

class StringHelperTest extends TestCase
{
    public function test_normalize_for_comparison_trims_whitespace(): void
    {
        $result = StringHelper::normalizeForComparison('  Test Title  ');
        $this->assertEquals('test title', $result);
    }

    public function test_normalize_for_comparison_removes_notion_prefix(): void
    {
        $result = StringHelper::normalizeForComparison('Summary of Incident - Test Issue');
        $this->assertEquals('test issue', $result);
    }

    public function test_normalize_for_comparison_removes_notion_prefix_with_extra_spaces(): void
    {
        $result = StringHelper::normalizeForComparison('Summary of Incident -  Test Issue  ');
        $this->assertEquals('test issue', $result);
    }

    public function test_normalize_for_comparison_collapses_multiple_spaces(): void
    {
        $result = StringHelper::normalizeForComparison('Test    Multiple    Spaces');
        $this->assertEquals('test multiple spaces', $result);
    }

    public function test_normalize_for_comparison_is_case_insensitive(): void
    {
        $result1 = StringHelper::normalizeForComparison('TEST TITLE');
        $result2 = StringHelper::normalizeForComparison('test title');
        $this->assertEquals($result1, $result2);
    }

    public function test_normalize_for_comparison_handles_mixed_case(): void
    {
        $result = StringHelper::normalizeForComparison('TeST TiTLe');
        $this->assertEquals('test title', $result);
    }

    public function test_is_duplicate_title_detects_exact_match(): void
    {
        $result = StringHelper::isDuplicateTitle('Test Issue', 'Test Issue');
        $this->assertTrue($result);
    }

    public function test_is_duplicate_title_detects_whitespace_difference(): void
    {
        $result = StringHelper::isDuplicateTitle('Test Issue', 'Test Issue ');
        $this->assertTrue($result);
    }

    public function test_is_duplicate_title_detects_leading_trailing_spaces(): void
    {
        $result = StringHelper::isDuplicateTitle('  Test Issue  ', 'Test Issue');
        $this->assertTrue($result);
    }

    public function test_is_duplicate_title_detects_case_difference(): void
    {
        $result = StringHelper::isDuplicateTitle('Test Issue', 'test issue');
        $this->assertTrue($result);
    }

    public function test_is_duplicate_title_detects_all_caps_difference(): void
    {
        $result = StringHelper::isDuplicateTitle('TEST ISSUE', 'test issue');
        $this->assertTrue($result);
    }

    public function test_is_duplicate_title_detects_multiple_spaces(): void
    {
        $result = StringHelper::isDuplicateTitle('Test    Issue', 'Test Issue');
        $this->assertTrue($result);
    }

    public function test_is_duplicate_title_detects_notion_prefix(): void
    {
        $result = StringHelper::isDuplicateTitle('Summary of Incident - Test Issue', 'Test Issue');
        $this->assertTrue($result);
    }

    public function test_is_duplicate_title_false_for_different_titles(): void
    {
        $result = StringHelper::isDuplicateTitle('Login Error', 'Database Error');
        $this->assertFalse($result);
    }

    public function test_is_duplicate_title_false_for_similar_but_different(): void
    {
        $result = StringHelper::isDuplicateTitle('Login Error', 'Login Failure');
        $this->assertFalse($result);
    }

    public function test_is_duplicate_title_handles_combined_differences(): void
    {
        // All of: case, spaces, and notion prefix
        $result = StringHelper::isDuplicateTitle('SUMMARY OF INCIDENT -  TEST   ISSUE  ', 'test issue');
        $this->assertTrue($result);
    }

    public function test_normalize_for_comparison_handles_empty_string(): void
    {
        $result = StringHelper::normalizeForComparison('');
        $this->assertEquals('', $result);
    }

    public function test_normalize_for_comparison_handles_only_spaces(): void
    {
        $result = StringHelper::normalizeForComparison('     ');
        $this->assertEquals('', $result);
    }
}
