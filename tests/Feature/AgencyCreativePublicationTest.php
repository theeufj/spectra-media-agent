<?php

namespace Tests\Feature;

use App\Jobs\GenerateAdCopy;
use App\Jobs\GenerateImage;
use App\Jobs\GenerateVideo;
use App\Models\Campaign;
use App\Models\CreativeBrief;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\KnowledgeBase;
use App\Models\Strategy;
use App\Services\Creative\CreativePublication;
use App\Services\Creative\CreativeRelease;
use App\Services\Creative\CreativeRevisions;
use App\Services\FacebookAds\AdService;
use App\Services\FacebookAds\AdSetService;
use App\Services\FacebookAds\CampaignService;
use App\Services\FacebookAds\CreativeService;
use App\Services\FacebookAds\PublishFacebookRevision;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgencyCreativePublicationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_signed_off_edit_only_regenerates_changed_brief_and_preserves_previous_copy_and_approval(): void
    {
        $strategy = Strategy::factory()->create(['platform' => 'Facebook Ads', 'campaign_type' => 'display', 'signed_off_at' => now()]);
        $old = $strategy->adCopies()->create(['platform' => 'Facebook Ads', 'headlines' => ['Original'], 'descriptions' => ['Original offer'], 'should_deploy' => true]);
        $initial = ['fingerprint' => str_repeat('a', 64), 'copy_ids' => [$old->id]];
        $strategy->update(['creative_approval' => $initial]);
        app(CreativeRevisions::class)->edit($strategy, ['ad_copy_strategy' => 'Use the verified autumn offer.']);
        $strategy->refresh();
        $this->assertSame(['ad_copy_strategy'], $strategy->creative_revision['affected']);
        $this->assertSame($initial['fingerprint'], $strategy->creative_approval['fingerprint']);
        $this->assertSame(['Original'], $old->fresh()->headlines);
        Queue::assertPushed(GenerateAdCopy::class, 1);
        Queue::assertNotPushed(GenerateImage::class);
        Queue::assertNotPushed(GenerateVideo::class);
        $this->assertSame([], app(CreativeRevisions::class)->edit($strategy, ['ad_copy_strategy' => 'Use the verified autumn offer.']));
        Queue::assertPushed(GenerateAdCopy::class, 1);
    }

    public function test_approved_brief_generates_once_and_keeps_the_current_release(): void
    {
        $strategy = Strategy::factory()->create(['platform' => 'Google Ads', 'campaign_type' => 'performance_max', 'signed_off_at' => now()]);
        $brief = CreativeBrief::create(['campaign_id' => $strategy->campaign_id, 'customer_id' => $strategy->campaign->customer_id,
            'platform' => 'google', 'brief_type' => 'pmax_replacement', 'status' => 'pending', 'created_by_agent' => 'pmax_asset_optimization',
            'ai_brief' => 'Show the verified product use.', 'context' => ['strategy_id' => $strategy->id, 'field_type' => 'MARKETING_IMAGE']]);
        app(CreativeRevisions::class)->actionBrief($brief);
        app(CreativeRevisions::class)->actionBrief($brief);
        $this->assertSame('actioned', $brief->fresh()->status);
        $this->assertSame($brief->id, $strategy->fresh()->creative_revision['brief_id']);
        Queue::assertPushed(GenerateImage::class, 1);
        Queue::assertNotPushed(GenerateAdCopy::class);
    }

    public function test_only_ready_customer_owned_evidence_changes_the_release_and_budget_roundtrips(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->for($customer)->create(['approved_daily_budget' => 10]);
        $strategy = Strategy::factory()->for($campaign)->create(['daily_budget' => 10, 'platform' => 'Facebook Ads', 'campaign_type' => 'display']);
        $release = app(CreativeRelease::class);
        $fingerprint = $release->selection($strategy)['fingerprint'];
        $this->assertSame($fingerprint, $release->selection($strategy->fresh())['fingerprint']);
        $fields = ['user_id' => \App\Models\User::factory()->create()->id, 'title' => 'Product price list', 'source_type' => 'text', 'processing_status' => 'ready',
            'content' => 'The verified price is US$99 monthly.', 'source_version' => 1];
        KnowledgeBase::create(['customer_id' => Customer::factory()->create()->id, 'url' => 'text://other-price'] + $fields);
        $source = KnowledgeBase::create(['customer_id' => $customer->id, 'url' => 'text://owned-price', 'excluded_at' => now()] + $fields);
        $this->assertSame($fingerprint, $release->selection($strategy->fresh())['fingerprint']);
        $source->forceFill(['excluded_at' => null])->save();
        $this->assertNotSame($fingerprint, $release->selection($strategy->fresh())['fingerprint']);
        $after = $release->selection($strategy->fresh())['fingerprint'];
        $strategy->update(['daily_budget' => 15]);
        $this->assertNotSame($after, $release->selection($strategy->fresh())['fingerprint']);
    }

    public function test_rotate_only_a_preapproved_variant_with_the_same_copy_pair_and_mandate(): void
    {
        $strategy = Strategy::factory()->create(['platform' => 'Facebook Ads', 'campaign_type' => 'display', 'deployment_status' => 'active',
            'signed_off_at' => now(), 'daily_budget' => 10, 'facebook_campaign_id' => '766321']);
        $strategy->adCopies()->create(['platform' => 'Facebook Ads', 'headlines' => ['First', 'Second'], 'descriptions' => ['Body one', 'Body two'], 'should_deploy' => true]);
        $items = collect(['first', 'second'])->map(fn ($name) => ImageCollateral::create(['strategy_id' => $strategy->id,
            'campaign_id' => $strategy->campaign_id, 'platform' => 'Facebook Ads', 'format' => 'square', 's3_path' => $name,
            'cloudfront_url' => '/'.$name, 'is_active' => true, 'should_deploy' => true]));
        [$first, $second] = $items->all();
        $strategy->update(['execution_result' => ['platform_ids' => ['image_ad_'.$first->id => '1001', 'image_ad_'.$second->id => '1002']]]);
        $release = app(CreativeRelease::class);
        $original = $release->selection($strategy)['fingerprint'];
        $strategy->update(['creative_release_review' => ['status' => 'passed', 'issues' => [], 'fingerprint' => $original,
            'image_hashes' => [$first->id => hash('sha256', 'one'), $second->id => hash('sha256', 'two')]]]);
        $release->approve($strategy, $original);
        $previousCopy = $strategy->creative_approval['facebook_variants']['image_ad_'.$second->id];
        $this->assertSame('Second', $previousCopy['headline']);
        $rotation = app(\App\Services\Creative\ApprovedVariantRotation::class);
        $this->assertFalse($rotation->proposeMeta($strategy->campaign, '9999'));
        $this->assertTrue($rotation->proposeMeta($strategy->campaign, '1001'));
        $strategy->refresh();
        $this->assertFalse($first->fresh()->should_deploy);
        $this->assertTrue($second->fresh()->should_deploy);
        $this->assertSame($original, $strategy->creative_approval['fingerprint']);
        $verdict = ['status' => 'passed', 'issues' => [], 'fingerprint' => $release->selection($strategy)['fingerprint'], 'image_hashes' => [$second->id => hash('sha256', 'two')]];
        $strategy->update(['creative_release_review' => $verdict]);
        $rotation->complete($strategy, $verdict);
        $this->assertSame($previousCopy, $strategy->fresh()->creative_approval['facebook_variants']['image_ad_'.$second->id]);
        $this->assertSame([$second->id], $strategy->fresh()->creative_approval['image_ids']);
        Queue::assertPushed(\App\Jobs\PublishApprovedCreative::class, 1);
    }

    public function test_meta_stages_reads_review_then_pauses_only_its_known_predecessor_and_reads_back(): void
    {
        config(['filesystems.disks.s3.bucket' => null, 'campaigns.testing_mode' => false]);
        Storage::fake('public');
        $customer = Customer::factory()->create(['google_ads_link_status' => 'active', 'facebook_ads_account_id' => 'act_123456']);
        $campaign = Campaign::factory()->for($customer)->create(['status' => 'active', 'daily_budget' => 10, 'approved_daily_budget' => 10, 'budget_confirmed_at' => now(), 'end_date' => now()->addWeek()]);
        $strategy = Strategy::factory()->for($campaign)->create(['platform' => 'Facebook Ads', 'campaign_type' => 'display', 'signed_off_at' => now(),
            'deployment_status' => 'active', 'facebook_campaign_id' => '456789', 'facebook_adset_id' => '234567', 'facebook_ad_id' => '1001',
            'daily_budget' => 10, 'execution_result' => ['platform_ids' => ['image_ad_1' => '1001']]]);
        $strategy->adCopies()->create(['platform' => 'Facebook Ads', 'headlines' => ['Our store'], 'descriptions' => ['Explore our store.'], 'should_deploy' => true]);
        $path = 'collateral/images/'.$campaign->id.'/new.png';
        Storage::disk('public')->put($path, 'approved render');
        $image = ImageCollateral::create(['strategy_id' => $strategy->id, 'campaign_id' => $campaign->id, 'platform' => 'Facebook Ads', 'format' => 'square',
            's3_path' => $path, 'cloudfront_url' => '/new.png', 'is_active' => true, 'should_deploy' => true]);
        $release = app(CreativeRelease::class);
        $fingerprint = $release->selection($strategy)['fingerprint'];
        $strategy->update(['creative_release_review' => ['fingerprint' => $fingerprint, 'status' => 'passed', 'issues' => [], 'image_hashes' => [$image->id => hash('sha256', 'approved render')]]]);
        $release->approve($strategy, $fingerprint);
        CreativePublication::begin($strategy, $fingerprint, []);
        $campaigns = $this->createMock(CampaignService::class);
        $campaigns->method('getCampaign')->willReturn(['id' => '456789', 'account_id' => '123456', 'effective_status' => 'ACTIVE']);
        $this->app->bind(CampaignService::class, fn () => $campaigns);
        $sets = $this->createMock(AdSetService::class);
        $sets->method('listAdSets')->willReturn([['id' => '234567', 'status' => 'ACTIVE', 'daily_budget' => '1000']]);
        $this->app->bind(AdSetService::class, fn () => $sets);
        $creative = $this->createMock(CreativeService::class);
        $creative->expects($this->once())->method('reviewedImageHash')->willReturn('abc');
        $creative->expects($this->once())->method('createImageCreative')->willReturn(['id' => '3003']);
        $this->app->bind(CreativeService::class, fn () => $creative);
        $ads = $this->createMock(AdService::class);
        $base = ['account_id' => '123456', 'campaign_id' => '456789', 'adset_id' => '234567', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE'];
        $old = ['id' => '1001', 'name' => 'Old'] + $base;
        $other = ['id' => '9999', 'name' => 'Outside this release'] + $base;
        $name = 'Site to Spend '.$strategy->id.' '.$fingerprint.' image_ad_'.$image->id;
        $new = ['id' => '2002', 'name' => $name, 'creative' => ['id' => '3003']] + $base;
        $ads->expects($this->exactly(5))->method('listAds')->willReturn(
            [$old, $other], [$old, $other, ['status' => 'PAUSED', 'effective_status' => 'PAUSED'] + $new],
            [$old, $other, ['effective_status' => 'PENDING_REVIEW'] + $new], [$old, $other, $new],
            [['status' => 'PAUSED'] + $old, $new, $other]);
        $ads->expects($this->once())->method('createAd')->with('123456', '234567', $name, '3003', 'PAUSED')->willReturn(['id' => '2002']);
        $ads->expects($this->once())->method('resumeAd')->with('2002')->willReturn(true);
        $ads->expects($this->once())->method('pauseAd')->with('1001')->willReturn(true);
        $this->app->bind(AdService::class, fn () => $ads);
        $publisher = new PublishFacebookRevision;
        for ($step = 0; $step < 4; $step++) {
            $this->assertFalse($publisher->advance($strategy->fresh(), $fingerprint));
            $this->assertSame('1001', $strategy->fresh()->facebook_ad_id);
        }
        $this->assertTrue($publisher->advance($strategy->fresh(), $fingerprint));
        $this->assertSame('2002', $strategy->fresh()->facebook_ad_id);
        $this->assertSame('published', $strategy->fresh()->creative_publication['phase']);
    }
}
