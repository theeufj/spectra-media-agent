<?php

namespace Tests\Feature;

use App\Jobs\GenerateImage;
use App\Models\Campaign;
use App\Models\CreativeUsage;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Plan;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A campaign at its image limit is refused out loud, and free of charge.
 *
 * There are two independent limits and only one of them was visible. The
 * controller checked the monthly creative quota, passed, dispatched the job,
 * recorded usage and told the page "generation has been queued". The job then
 * checked a different gate — the per-campaign free-tier cap — logged "Image
 * limit reached" and returned. It therefore *succeeded*: no image, no
 * exception, no failed_jobs row, an empty queue. Nothing existed for the UI to
 * react to, so the spinner ran for five minutes and then reported that
 * generation was "taking longer than expected". It had been declined in about
 * one second.
 *
 * Observed in production on 2026-09-11: requested 04:40:10, refused 04:40:11,
 * and the customer's September quota was spent on it regardless.
 */
class ImageGenerationLimitRefusalTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Campaign $campaign;

    private Strategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        /*
         * The free plan has to exist for the quota assertion to mean anything.
         *
         * recordUsage() returns early when resolveCurrentPlan() yields no
         * creative_limits, and resolveCurrentPlan() falls back to the plan with
         * slug 'free'. Without this row a factory user reads as an unlimited
         * agency account, no usage is ever written, and a test asserting "no
         * quota was spent" passes against the broken controller too.
         */
        Plan::firstOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'price_cents' => 0,
                'is_free' => true,
                'is_active' => true,
                'creative_limits' => ['image_generations' => 5, 'video_generations' => 1, 'refinements' => 3],
            ]
        );

        // Unsubscribed: the free-tier cap only applies to customers without a
        // subscription, and a factory customer has none.
        $customer = Customer::factory()->create();
        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $customer->users()->attach($this->user->id, ['role' => 'owner']);

        $this->campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $this->strategy = Strategy::factory()->create(['campaign_id' => $this->campaign->id]);
    }

    private function fillToLimit(): void
    {
        for ($i = 0; $i < ImageCollateral::FREE_TIER_LIMIT_PER_CAMPAIGN; $i++) {
            ImageCollateral::create([
                'campaign_id' => $this->campaign->id,
                'strategy_id' => $this->strategy->id,
                'platform' => $this->strategy->platform,
                's3_path' => "collateral/images/{$this->campaign->id}/existing_{$i}.png",
                'cloudfront_url' => "https://cdn.example.com/existing_{$i}.png",
                'format' => 'square',
            ]);
        }
    }

    private function requestGeneration(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post(route('campaigns.collateral.image.store', [
            'campaign' => $this->campaign->id,
            'strategy' => $this->strategy->id,
        ]));
    }

    public function test_a_campaign_under_the_limit_still_generates(): void
    {
        $this->requestGeneration()->assertRedirect();

        Queue::assertPushed(GenerateImage::class);
    }

    public function test_a_campaign_at_its_limit_is_refused_rather_than_queued(): void
    {
        $this->fillToLimit();

        $this->requestGeneration()->assertRedirect();

        // The job is where the spinner's silence came from: dispatching it
        // produced a success that generated nothing.
        Queue::assertNotPushed(GenerateImage::class);
    }

    public function test_the_refusal_says_so_instead_of_claiming_it_was_queued(): void
    {
        $this->fillToLimit();

        $this->requestGeneration()->assertRedirect();

        $flash = session('flash');

        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsStringIgnoringCase('limit', $flash['message'] ?? '');
        $this->assertStringNotContainsStringIgnoringCase('queued', $flash['message'] ?? '');
    }

    public function test_a_refused_request_does_not_spend_a_months_generation(): void
    {
        $this->fillToLimit();

        $this->requestGeneration();

        $usage = CreativeUsage::where('user_id', $this->user->id)->first();

        $this->assertSame(
            0,
            (int) ($usage->image_generations_used ?? 0),
            'A generation the job was always going to decline must not consume quota.'
        );
    }
}
