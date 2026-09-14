<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Six pictures per campaign, counted as pictures.
 *
 * Two bugs met here and produced 24 cards for one campaign. The gate was
 * consulted three times in a row by GenerateStrategyCollateral before a single
 * row existed, so it always passed; and each of those three jobs then wrote as
 * many scenes as the prompt splitter happened to return. Campaign 36 got 4
 * pictures, campaign 40 got 8, from identical code.
 *
 * The counting was wrong underneath both. One scene is stored once per ad
 * format — square, 1200x628, 300x250 — so counting rows counted every picture
 * three times and made the plan's allowance a third of what it said.
 */
class ImageConceptCapTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(): Campaign
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create([
            'service_type' => 'setup_only',
            'setup_fee_paid_at' => now(),
        ]);
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        return Campaign::factory()->create(['customer_id' => $customer->id]);
    }

    /** Writes one scene as its three ad formats, the way GenerateImage does. */
    private function addConcept(Campaign $campaign): string
    {
        $key = (string) Str::uuid();

        foreach (['square', 'landscape', 'mrec'] as $format) {
            ImageCollateral::create([
                'campaign_id' => $campaign->id,
                'platform' => 'google',
                's3_path' => "collateral/images/{$campaign->id}/".Str::random(8).'.jpeg',
                'cloudfront_url' => 'https://example.test/'.Str::random(8).'.jpeg',
                'format' => $format,
                'concept_key' => $key,
            ]);
        }

        return $key;
    }

    public function test_three_ad_sizes_of_one_scene_are_one_picture(): void
    {
        $campaign = $this->campaign();
        $this->addConcept($campaign);

        $this->assertSame(3, ImageCollateral::where('campaign_id', $campaign->id)->count());
        $this->assertSame(1, ImageCollateral::conceptsForCampaign($campaign));
    }

    public function test_the_cap_binds_at_six_pictures(): void
    {
        $campaign = $this->campaign();

        for ($i = 0; $i < ImageCollateral::MAX_CONCEPTS_PER_CAMPAIGN; $i++) {
            $this->assertTrue(
                ImageCollateral::canGenerateForCampaign($campaign),
                "refused picture {$i}, below the cap",
            );
            $this->addConcept($campaign);
        }

        // 18 rows, 6 pictures, and the seventh is refused.
        $this->assertSame(18, ImageCollateral::where('campaign_id', $campaign->id)->count());
        $this->assertSame(6, ImageCollateral::conceptsForCampaign($campaign));
        $this->assertFalse(ImageCollateral::canGenerateForCampaign($campaign));
    }

    public function test_a_smaller_plan_allowance_still_wins(): void
    {
        $campaign = $this->campaign();
        $plan = Plan::firstOrCreate(['slug' => 'tiny'], ['name' => 'Tiny', 'price_cents' => 100, 'billing_interval' => 'month']);
        $plan->forceFill(['creative_limits' => ['image_generations' => 2]])->save();
        $campaign->customer->forceFill(['plan_id' => $plan->id])->save();

        $this->addConcept($campaign->fresh());
        $this->addConcept($campaign->fresh());

        // The cap is a ceiling, not an entitlement — it must not hand a
        // two-image plan six.
        $this->assertFalse(ImageCollateral::canGenerateForCampaign($campaign->fresh()));
    }

    public function test_rows_from_before_concept_keys_each_count_alone(): void
    {
        $campaign = $this->campaign();

        foreach (['square', 'landscape', 'mrec'] as $format) {
            ImageCollateral::create([
                'campaign_id' => $campaign->id,
                'platform' => 'google',
                's3_path' => 'legacy/'.Str::random(8).'.jpeg',
                'cloudfront_url' => 'https://example.test/legacy.jpeg',
                'format' => $format,
            ]);
        }

        /*
           Guessing a grouping for old rows would merge unrelated photographs
           and under-count what a campaign already holds. Each stands alone,
           which is exactly how they were counted before.
        */
        $this->assertSame(3, ImageCollateral::conceptsForCampaign($campaign));
    }
}
