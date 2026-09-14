<?php

namespace Tests\Feature;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * What a campaign is allowed to accumulate, and what regeneration removes.
 *
 * A one-time setup customer is sold ten images and had twenty-seven, all of
 * them ticked for deployment. Two separate holes:
 *
 *   - canGenerateForCampaign() returned true for any paid plan, so
 *     image_generations was decorative above the free tier. Collateral
 *     generation can run more than once against a strategy and nothing counted.
 *   - Force Regenerate promises "delete all generated collateral" and deleted
 *     only what hung off the strategies still attached, leaving every earlier
 *     round behind, keyed to strategies that no longer existed.
 */
class CollateralHousekeepingTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Campaign} */
    private function campaignOnPlan(int $imageAllowance): array
    {
        // pm_type, because regenerate-strategies sits behind the subscription
        // gate and hasSubscriptionAccess() accepts a payment method on file.
        $user = User::factory()->create(['pm_type' => 'card']);
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'user'])));

        $plan = Plan::firstOrCreate(['slug' => 'test-image-allowance'], [
            'name' => 'Test', 'price_cents' => 100, 'billing_interval' => 'month',
        ]);
        $plan->forceFill(['creative_limits' => ['image_generations' => $imageAllowance]])->save();

        $customer = Customer::factory()->create(['is_sandbox' => false, 'plan_id' => $plan->id]);
        $customer->users()->attach($user->id, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        return [$user, $campaign->fresh('customer')];
    }

    private function addImages(Campaign $campaign, int $count, string $source = 'generated'): void
    {
        foreach (range(1, $count) as $i) {
            ImageCollateral::create([
                'campaign_id' => $campaign->id,
                'platform' => 'Google Ads (SEM)',
                'source' => $source,
                's3_path' => "collateral/images/{$campaign->id}/{$source}-{$i}.jpeg",
                'cloudfront_url' => "https://example.test/{$source}-{$i}.jpeg",
            ]);
        }
    }

    public function test_a_plan_allowance_smaller_than_the_ceiling_still_binds(): void
    {
        [, $campaign] = $this->campaignOnPlan(imageAllowance: 3);

        $this->addImages($campaign, 2);
        $this->assertTrue(ImageCollateral::canGenerateForCampaign($campaign));

        $this->addImages($campaign, 1);

        // Three sold, three generated. "Paid accounts have no per-campaign
        // limit" is how twenty-seven happened.
        $this->assertFalse(ImageCollateral::canGenerateForCampaign($campaign));
    }

    public function test_the_ceiling_binds_even_on_a_generous_plan(): void
    {
        [, $campaign] = $this->campaignOnPlan(imageAllowance: 150);

        $this->addImages($campaign, ImageCollateral::MAX_CONCEPTS_PER_CAMPAIGN - 1);
        $this->assertTrue(ImageCollateral::canGenerateForCampaign($campaign));

        $this->addImages($campaign, 1);

        /*
           This test used to assert the opposite — that a 150-image plan kept
           its headroom per campaign — and that was the right rule when the
           allowance was the only limit. It is not what a person wants in front
           of them: one campaign generated eight scenes, stored as 24 cards,
           and nobody reviews 24 creatives. The allowance is now what the
           account may generate in total; six is what any single campaign puts
           on the page.
        */
        $this->assertFalse(ImageCollateral::canGenerateForCampaign($campaign));
    }

    public function test_regeneration_clears_what_it_generated_and_keeps_what_the_customer_uploaded(): void
    {
        // No s3 credentials configured in tests, so StorageHelper::delete is a
        // no-op — the rows are what this asserts.
        config(['filesystems.disks.s3.bucket' => null, 'filesystems.disks.s3.key' => null]);

        [$user, $campaign] = $this->campaignOnPlan(imageAllowance: 10);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'signed_off_at' => now()]);

        $this->addImages($campaign, 3, source: 'generated');
        $this->addImages($campaign, 2, source: 'uploaded');
        AdCopy::create([
            'strategy_id' => $strategy->id,
            'platform' => 'Google Ads (SEM)',
            'headlines' => ['Build Your Store in 5 Mins'],
            'descriptions' => ['Launch in five minutes.'],
        ]);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $campaign->customer_id])
            ->post(route('campaigns.regenerate-strategies', $campaign), ['force' => 1])
            ->assertRedirect();

        /*
           The dialog promises "delete all generated collateral". Strategy rows
           cascade their own images, but images belonging to the campaign rather
           than to a surviving strategy did not go anywhere, and neither did any
           of the files behind them — a bill that never stops.
        */
        $remaining = ImageCollateral::where('campaign_id', $campaign->id)->get();

        $this->assertCount(2, $remaining, 'generated collateral survived a force regeneration');
        $this->assertSame(
            ['uploaded', 'uploaded'],
            $remaining->pluck('source')->all(),
            "the customer's own uploads are not ours to delete",
        );
        $this->assertSame(0, AdCopy::where('strategy_id', $strategy->id)->count());
    }
}
