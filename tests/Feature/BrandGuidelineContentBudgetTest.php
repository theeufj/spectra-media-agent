<?php

namespace Tests\Feature;

use App\Services\BrandGuidelineExtractorService;
use Tests\TestCase;

/**
 * The extraction prompt gets a bounded, priority-ordered slice of the
 * site — not the whole crawl. A 405-page store used to push 1.25M chars
 * into one prompt and fail silently.
 */
class BrandGuidelineContentBudgetTest extends TestCase
{
    public function test_content_is_capped_per_chunk_and_in_total(): void
    {
        $chunks = array_fill(0, 50, str_repeat('x', 20000));

        $result = BrandGuidelineExtractorService::budgetedContent($chunks, 8000, 120000);

        $this->assertLessThanOrEqual(120000 + 50 * 30, strlen($result));
        // Per-chunk cap: first chunk contributes 8000, not 20000.
        $this->assertSame(8000, strlen(explode("\n\n---PAGE BREAK---\n\n", $result)[0]));
        // Priority order preserved: budget spent on the EARLIEST chunks.
        $this->assertGreaterThanOrEqual(15, count(explode('---PAGE BREAK---', $result)));
    }

    public function test_small_content_passes_through_untouched(): void
    {
        $result = BrandGuidelineExtractorService::budgetedContent(['about the business', 'our products']);

        $this->assertStringContainsString('about the business', $result);
        $this->assertStringContainsString('our products', $result);
    }
}
