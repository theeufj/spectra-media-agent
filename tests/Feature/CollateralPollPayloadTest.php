<?php

namespace Tests\Feature;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The endpoint the collateral spinner polls has to answer its question.
 *
 * The page asks "has anything landed for this signed-off strategy yet" by
 * reading ad_copies_count, image_collaterals_count and video_collaterals_count.
 * apiShow loaded bare strategies, so all three were absent, the front end read
 * them as zero, and the answer was permanently "no": a campaign whose images
 * had finished minutes earlier sat on "Generating your collateral... this
 * usually takes 1-2 minutes" until someone reloaded, and the auto-advance to
 * the collateral page could never fire.
 *
 * The Inertia endpoint for the same page always loaded the counts, which is why
 * the first render looked right and only the polling was wrong — and why a
 * comment in Show.jsx claimed the endpoint already returned them.
 */
class CollateralPollPayloadTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Campaign, Strategy} */
    private function signedOffCampaign(): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'signed_off_at' => now()]);

        return [$user, $campaign, $strategy];
    }

    public function test_the_counts_are_present_so_the_wait_can_end(): void
    {
        [$user, $campaign, $strategy] = $this->signedOffCampaign();

        ImageCollateral::create([
            'campaign_id' => $campaign->id,
            'strategy_id' => $strategy->id,
            'platform' => 'Google Ads (SEM)',
            's3_path' => 'collateral/images/x.jpeg',
            'cloudfront_url' => 'https://example.test/x.jpeg',
            'format' => 'square',
            'concept_key' => 'k',
        ]);

        AdCopy::create([
            'strategy_id' => $strategy->id,
            'platform' => 'Google Ads (SEM)',
            'headlines' => ['Build a Store in 5 Minutes'],
            'descriptions' => ['Launch in five minutes.'],
        ]);

        $payload = $this->actingAs($user)
            ->withSession(['active_customer_id' => $campaign->customer_id])
            ->getJson(route('api.campaigns.show', $campaign))
            ->assertOk()
            ->json();

        $s = $payload['strategies'][0];

        $this->assertSame(1, $s['image_collaterals_count']);
        $this->assertSame(1, $s['ad_copies_count']);
        $this->assertSame(0, $s['video_collaterals_count']);
    }

    public function test_a_strategy_with_nothing_yet_reports_zero_rather_than_nothing(): void
    {
        [$user, $campaign] = $this->signedOffCampaign();

        $payload = $this->actingAs($user)
            ->withSession(['active_customer_id' => $campaign->customer_id])
            ->getJson(route('api.campaigns.show', $campaign))
            ->assertOk()
            ->json();

        /*
           An absent key and a zero are the same thing to the page, which is
           exactly why this went unnoticed: the wait looked correct for a
           campaign that genuinely had nothing, and never ended for one that
           did. The key has to be there for the other assertion to mean
           anything.
        */
        $this->assertArrayHasKey('image_collaterals_count', $payload['strategies'][0]);
        $this->assertSame(0, $payload['strategies'][0]['image_collaterals_count']);
    }
}
