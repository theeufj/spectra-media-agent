<?php

namespace Tests\Feature;

use App\Jobs\ExtractBrandGuidelines;
use App\Jobs\HarvestWebsiteAssets;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\HarvestedAsset;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\User;
use App\Models\VideoCollateral;
use App\Services\BrandGuidelineExtractorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Customer-supplied media must actually reach ads and AI generation.
 * Wizard uploads used to be stored with strategy_id null and then orphaned
 * by strategy-scoped deploy queries — these tests pin the adoption rules.
 */
class CustomerMediaPipelineTest extends TestCase
{
    use DatabaseTransactions;

    private function actor(): array
    {
        // subscription_status active: the toggle endpoint lives in the
        // subscribed route group, and an unsubscribed user is redirected to
        // pricing instead of toggling.
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_status' => 'active',
        ]);
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        session(['active_customer_id' => $customer->id]);

        return [$user, $customer];
    }

    public function test_campaign_level_media_is_adopted_by_every_strategy_of_that_campaign(): void
    {
        [, $customer] = $this->actor();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategyA = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads']);
        $strategyB = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Facebook Ads']);

        $otherCampaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $otherStrategy = Strategy::factory()->create(['campaign_id' => $otherCampaign->id, 'platform' => 'Google Ads']);

        // A wizard upload: campaign-level, no strategy.
        $wizardUpload = ImageCollateral::create([
            'campaign_id' => $campaign->id,
            'strategy_id' => null,
            'platform' => 'google',
            's3_path' => 'collateral/images/x.jpg',
            'cloudfront_url' => 'https://cdn.example/x.jpg',
            'is_active' => true,
            'source' => 'uploaded',
        ]);

        // A strategy-specific image.
        $ownImage = ImageCollateral::create([
            'campaign_id' => $campaign->id,
            'strategy_id' => $strategyA->id,
            'platform' => 'google',
            's3_path' => 'collateral/images/y.jpg',
            'cloudfront_url' => 'https://cdn.example/y.jpg',
            'is_active' => true,
        ]);

        $idsForA = ImageCollateral::forStrategy($strategyA)->pluck('id');
        $idsForB = ImageCollateral::forStrategy($strategyB)->pluck('id');
        $idsForOther = ImageCollateral::forStrategy($otherStrategy)->pluck('id');

        $this->assertTrue($idsForA->contains($wizardUpload->id));
        $this->assertTrue($idsForA->contains($ownImage->id));
        // Sibling strategy shares the campaign-level upload but not A's own image.
        $this->assertTrue($idsForB->contains($wizardUpload->id));
        $this->assertFalse($idsForB->contains($ownImage->id));
        // Another campaign's strategy sees neither.
        $this->assertTrue($idsForOther->isEmpty());
    }

    public function test_campaign_level_videos_are_shared_across_strategies(): void
    {
        [, $customer] = $this->actor();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategyA = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads']);
        $strategyB = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Facebook Ads']);

        $sharedVideo = VideoCollateral::create([
            'campaign_id' => $campaign->id,
            'strategy_id' => null,
            'platform' => 'Facebook Ads',
            'status' => 'completed',
            'is_active' => true,
        ]);

        $this->assertTrue(VideoCollateral::forStrategy($strategyA)->whereKey($sharedVideo->id)->exists());
        $this->assertTrue(VideoCollateral::forStrategy($strategyB)->whereKey($sharedVideo->id)->exists());
    }

    public function test_seed_images_are_never_in_the_deployable_set(): void
    {
        [, $customer] = $this->actor();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads']);

        ImageCollateral::create([
            'campaign_id' => $campaign->id,
            'strategy_id' => null,
            'platform' => 'google',
            's3_path' => 'collateral/images/seed.jpg',
            'cloudfront_url' => 'https://cdn.example/seed.jpg',
            'is_active' => true,
            'is_seed' => true,
            'should_deploy' => false,
            'source' => 'uploaded',
        ]);

        $deployable = ImageCollateral::forStrategy($strategy)
            ->where('is_active', true)
            ->where('should_deploy', true)
            ->count();

        $this->assertSame(0, $deployable);
    }

    public function test_the_seed_flag_can_be_toggled_from_the_collateral_page(): void
    {
        [$user, $customer] = $this->actor();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $image = ImageCollateral::create([
            'campaign_id' => $campaign->id,
            'strategy_id' => null,
            'platform' => 'google',
            's3_path' => 'collateral/images/z.jpg',
            'cloudfront_url' => 'https://cdn.example/z.jpg',
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('deployment.toggle-collateral'), [
            'type' => 'image',
            'id' => $image->id,
            'field' => 'is_seed',
        ])->assertRedirect();

        $this->assertTrue($image->fresh()->is_seed);

        // Videos cannot be seeds.
        $video = VideoCollateral::create([
            'campaign_id' => $campaign->id,
            'strategy_id' => null,
            'platform' => 'google',
            'status' => 'completed',
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('deployment.toggle-collateral'), [
            'type' => 'video',
            'id' => $video->id,
            'field' => 'is_seed',
        ])->assertStatus(400);
    }

    public function test_a_harvested_asset_can_be_promoted_as_an_ai_seed(): void
    {
        [$user, $customer] = $this->actor();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads']);

        $asset = HarvestedAsset::create([
            'customer_id' => $customer->id,
            'source_url' => 'https://example.com/product.jpg',
            's3_path' => 'harvested/1/product.jpg',
            'cloudfront_url' => 'https://cdn.example/product.jpg',
            'classification' => 'product',
            'status' => 'processed',
        ]);

        $this->actingAs($user)->post(route('harvested-assets.use', $asset), [
            'campaign_id' => $campaign->id,
            'strategy_id' => $strategy->id,
            'variant' => 'original',
            'as_seed' => true,
        ])->assertRedirect();

        $seed = ImageCollateral::where('campaign_id', $campaign->id)->where('is_seed', true)->first();
        $this->assertNotNull($seed);
        // Campaign-level so every strategy's generation sees it; never deployed raw.
        $this->assertNull($seed->strategy_id);
        $this->assertFalse($seed->should_deploy);
        $this->assertSame('harvested', $seed->source);
    }

    public function test_onboarding_extraction_kicks_off_the_website_asset_harvest(): void
    {
        Queue::fake();
        [, $customer] = $this->actor();

        $guideline = new \App\Models\BrandGuideline;
        /** @var BrandGuidelineExtractorService&\Mockery\MockInterface $extractor */
        $extractor = Mockery::mock(BrandGuidelineExtractorService::class);
        $extractor->shouldReceive('extractGuidelines')->andReturn($guideline);

        \Illuminate\Support\Facades\Mail::fake();
        (new ExtractBrandGuidelines($customer))->handle($extractor);

        Queue::assertPushed(HarvestWebsiteAssets::class,
            fn ($job) => $this->getProtected($job, 'customer')->id === $customer->id);
    }

    private function getProtected(object $job, string $property): mixed
    {
        $ref = new \ReflectionProperty($job, $property);

        return $ref->getValue($job);
    }
}
