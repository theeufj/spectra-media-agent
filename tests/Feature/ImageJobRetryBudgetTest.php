<?php

namespace Tests\Feature;

use App\Jobs\GenerateImage;
use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\AdminMonitorService;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Waiting for the ad copy must not retire the job.
 *
 * GenerateImage releases itself back to the queue while it waits for the copy
 * its headline is composited from — and a release counts as an attempt. With
 * no $tries and no retryUntil, Laravel's default of one attempt meant the very
 * first release killed it: MaxAttemptsExceededException, zero images, and a
 * strategy left carrying "has been attempted too many times" where a customer
 * should have had four creatives.
 *
 * CrawlPage and ExtractBrandGuidelines both carry this pairing for this exact
 * reason and both say so in their comments. The release was added without it.
 */
class ImageJobRetryBudgetTest extends TestCase
{
    use DatabaseTransactions;

    public function test_waiting_for_copy_does_not_call_ai_services(): void
    {
        $campaign = Campaign::factory()->create();
        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'imagery_strategy' => 'Sunlit architectural photographs arranged on a deep navy surface.',
        ]);
        $gemini = $this->mock(GeminiService::class, function ($mock) {
            $mock->shouldNotReceive('generateContent');
        });
        $monitor = $this->mock(AdminMonitorService::class, function ($mock) {
            $mock->shouldNotReceive('reviewImagePrompt');
        });

        $job = (new GenerateImage($campaign, $strategy))->withFakeQueueInteractions();
        $job->handle($gemini, $monitor);

        $job->assertReleased(20);
    }

    public function test_a_release_cannot_retire_the_job(): void
    {
        $job = new GenerateImage(Campaign::factory()->make(), new Strategy);

        /*
           A $tries cap counts every wait as an attempt. retryUntil() also
           suppresses the worker's maxTries check outright, which is the part
           that actually stops a release from retiring the job.
        */
        $this->assertFalse(
            (new \ReflectionClass($job))->hasProperty('tries'),
            'a $tries cap counts the waits for ad copy as failures',
        );

        $this->assertSame(3, $job->maxExceptions, 'three genuine errors, not three attempts');
    }

    public function test_the_window_outlasts_the_waiting_it_allows(): void
    {
        $job = new GenerateImage(Campaign::factory()->make(), new Strategy);

        $reflection = new \ReflectionClass($job);
        $waitAttempts = $reflection->getConstant('COPY_WAIT_ATTEMPTS');
        $waitSeconds = $reflection->getConstant('COPY_WAIT_SECONDS');

        // The window has to cover the full wait for copy plus the generation
        // that follows it, or the job dies mid-way through doing its job.
        $this->assertGreaterThan(
            now()->addSeconds($waitAttempts * $waitSeconds)->getTimestamp(),
            $job->retryUntil()->getTimestamp(),
            'the retry window is shorter than the waiting the job is told to do',
        );
    }

    public function test_it_still_gives_up_eventually(): void
    {
        $job = new GenerateImage(Campaign::factory()->make(), new Strategy);

        // Unbounded would mean a job whose ad copy never arrives circles for
        // ever, holding a worker and a slot of the campaign's allowance.
        $this->assertLessThan(
            now()->addHour()->getTimestamp(),
            $job->retryUntil()->getTimestamp(),
        );
    }
}
