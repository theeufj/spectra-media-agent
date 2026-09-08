<?php

namespace Tests\Feature;

use App\Jobs\ExtractBrandGuidelines;
use App\Models\BrandGuideline;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\SiteScanFailed;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A copy of the scan that lost the overlap lock must not tell the customer
 * their site scan failed.
 *
 * The onboarding chain dispatches this job from every batch that finishes, so
 * several copies race for one WithoutOverlapping lock. A release counts as an
 * attempt, so with `tries = 3` a loser burned all three on 120s deferrals and
 * died at ~T+360 — past the winner's own 300s timeout. failed() then mailed
 * every user "our analysis kept failing", quite possibly alongside the winner's
 * SiteScanCompleted. CrawlPage documents the same hazard and solves it the same
 * way: count exceptions, bound the deferrals with retryUntil().
 */
class BrandGuidelineExtractionRetryTest extends TestCase
{
    use DatabaseTransactions;

    private function customerWithUser(): Customer
    {
        $customer = Customer::factory()->create();
        $customer->users()->attach(User::factory()->create()->id, ['role' => 'owner']);

        return $customer->fresh();
    }

    public function test_a_release_from_the_overlap_lock_cannot_retire_the_job(): void
    {
        $customer = $this->customerWithUser();
        $job = new ExtractBrandGuidelines($customer);

        $this->assertFalse(
            (new \ReflectionClass($job))->hasProperty('tries'),
            'A $tries cap counts lock releases as attempts; that is what killed the losing copy.'
        );

        $this->assertSame(3, $job->maxExceptions, 'Three genuine errors, not three attempts.');

        $this->assertGreaterThan(
            now()->addMinutes(10)->getTimestamp(),
            $job->retryUntil()->getTimestamp(),
            'The window must outlast the lock (600s expireAfter) plus the winner run it is waiting on.'
        );
    }

    public function test_a_failure_does_not_email_the_customer_when_a_guideline_already_exists(): void
    {
        Notification::fake();

        $customer = $this->customerWithUser();

        BrandGuideline::create([
            'customer_id' => $customer->id,
            'brand_voice' => ['primary_tone' => 'direct'],
            'tone_attributes' => ['direct'],
            'target_audience' => ['primary' => 'Homeowners'],
            'messaging_themes' => ['Done properly'],
            'unique_selling_propositions' => ['One specialist end to end'],
            'color_palette' => ['primary_colors' => ['#1A1D20']],
            'typography' => ['heading_style' => 'Bold sans-serif'],
            'visual_style' => ['overall_aesthetic' => 'modern'],
            'brand_personality' => ['archetype' => 'Everyman'],
            'extraction_quality_score' => 80,
            'extracted_at' => now(),
        ]);

        (new ExtractBrandGuidelines($customer))->failed(new \RuntimeException('lock released too many times'));

        Notification::assertNothingSent();
    }

    public function test_a_failure_still_emails_the_customer_when_nothing_was_extracted(): void
    {
        // The other half: this is the end of the onboarding chain, and silence
        // leaves the customer watching "we're scanning your website" for ever.
        Notification::fake();

        $customer = $this->customerWithUser();

        (new ExtractBrandGuidelines($customer))->failed(new \RuntimeException('Gemini returned 500'));

        Notification::assertSentTo($customer->users, SiteScanFailed::class);
    }
}
