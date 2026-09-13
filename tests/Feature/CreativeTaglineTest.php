<?php

namespace Tests\Feature;

use App\Jobs\GenerateImage;
use App\Models\AdCopy;
use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Strategy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The one line of brand copy burnt into every creative.
 *
 * It was unique_selling_propositions[0], cut to 29 characters with an ellipsis
 * appended. A USP is written for the strategy model to reason from, not for a
 * banner — the one that exposed this ran to 133 characters:
 *
 *   "Conversational AI agent that generates a full, customized store (design,
 *    copy, SEO, products, and Stripe checkout) in under 5 minutes"
 *
 * Cut at 29 that is "Conversational AI agent that …", and it was burnt into all
 * nine creatives in the set. Not a layout bug: a truncated clause is a broken
 * promise, rendered into pixels, on every ad the customer paid for.
 *
 * Ad headlines are the right source and cost nothing extra. Google caps them at
 * 30 characters, so approved copy clears the banner by construction, and it is
 * the exact wording the customer signed off.
 */
class CreativeTaglineTest extends TestCase
{
    use DatabaseTransactions;

    private function tagline(?AdCopy $adCopy, ?BrandGuideline $brand): ?string
    {
        $job = new GenerateImage(Campaign::factory()->make(), new Strategy);

        $method = new \ReflectionMethod($job, 'taglineFor');
        $method->setAccessible(true);

        return $method->invoke($job, $adCopy, $brand);
    }

    public function test_an_approved_headline_is_used_whole(): void
    {
        $adCopy = new AdCopy(['headlines' => [
            'Build Your Store in 5 Mins',
            'Try Free - No Card Required',
            '0% Platform Transaction Fees',
        ]]);

        // The longest that still fits: it carries the most, and all of them
        // fit. Whichever is chosen, it is never cut.
        $this->assertSame('0% Platform Transaction Fees', $this->tagline($adCopy, null));
    }

    public function test_nothing_is_ever_truncated(): void
    {
        $brand = new BrandGuideline([
            'unique_selling_propositions' => [
                'Conversational AI agent that generates a full, customized store (design, copy, SEO, products, and Stripe checkout) in under 5 minutes',
            ],
            'messaging_themes' => [
                'Zero-friction AI store creation through natural conversation in under 5 minutes',
            ],
        ]);

        // Both sources are too long to fit. The old code returned the first
        // 29 characters of the USP with an ellipsis; the right answer is that
        // there is no tagline, and the banner carries the brand name alone.
        $result = $this->tagline(null, $brand);

        $this->assertNull($result);
    }

    public function test_a_headline_that_does_not_fit_is_skipped_rather_than_cut(): void
    {
        $adCopy = new AdCopy(['headlines' => [
            'An unusually long headline that would never fit on the banner at all',
            'Launch in 5 Minutes',
        ]]);

        $this->assertSame('Launch in 5 Minutes', $this->tagline($adCopy, null));
    }

    public function test_a_short_messaging_theme_is_a_fair_fallback(): void
    {
        $brand = new BrandGuideline([
            'unique_selling_propositions' => ['Something far too long to ever fit on a banner line'],
            'messaging_themes' => ['Build it in five minutes'],
        ]);

        // No approved copy yet — a theme that fits as written is still better
        // than a bare brand name. It is used only because it fits.
        $this->assertSame('Build it in five minutes', $this->tagline(null, $brand));
    }

    public function test_a_labelled_theme_loses_its_label(): void
    {
        $brand = new BrandGuideline([
            'messaging_themes' => ['Speed: Live in five minutes'],
        ]);

        // "Speed:" is the strategy model's own annotation, not copy.
        $this->assertSame('Live in five minutes', $this->tagline(null, $brand));
    }

    public function test_no_copy_and_no_brand_means_no_tagline(): void
    {
        $this->assertNull($this->tagline(null, null));
    }
}
