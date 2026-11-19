<?php

namespace Tests\Feature;

use App\Features\AutoHealing;
use App\Jobs\RetireReviewedPerformanceMaxImages;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\Creative\CreativeRelease;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\PerformanceMaxServices\RefreshPerformanceMaxAssets;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class RefreshPerformanceMaxAssetsTest extends TestCase
{
    use DatabaseTransactions;

    private const ACCOUNT = '1234567890';

    private const CAMPAIGN = 'customers/1234567890/campaigns/456';

    private const GROUP = 'customers/1234567890/assetGroups/789';

    private function strategy(): Strategy
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => self::ACCOUNT, 'service_type' => 'managed']);
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id, 'status' => 'active', 'platform_status' => 'ENABLED',
            'google_ads_campaign_id' => self::CAMPAIGN,
            'daily_budget' => '60.00', 'approved_daily_budget' => '60.00', 'budget_confirmed_at' => now(),
        ]);

        return Strategy::factory()->create([
            'campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'campaign_type' => 'performance_max',
            'google_ads_campaign_id' => self::CAMPAIGN, 'signed_off_at' => now(), 'deployment_status' => 'active',
            'execution_result' => ['metadata' => ['google_pmax_staging' => [
                'campaign_resource' => self::CAMPAIGN, 'asset_group' => self::GROUP,
                'budget_micros' => 60_000_000, 'final_urls' => ['https://example.test/management'],
            ]]],
        ]);
    }

    private function service(Strategy $strategy, GoogleAdsServiceClient $google, string $remoteStatus = 'ENABLED', array $links = [], ?array $after = null): RefreshPerformanceMaxAssets
    {
        $reader = $this->createMock(ReadCampaignConfiguration::class);
        $reader->expects($this->once())->method('performanceMax')->with(self::ACCOUNT, self::CAMPAIGN)->willReturn([
            'campaign' => ['campaign' => [
                'resourceName' => self::CAMPAIGN, 'status' => $remoteStatus,
                'advertisingChannelType' => 'PERFORMANCE_MAX', 'biddingStrategyType' => 'MAXIMIZE_CONVERSIONS',
                'assetAutomationSettings' => [
                    ['assetAutomationType' => 'FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION', 'assetAutomationStatus' => 'OPTED_OUT'],
                    ['assetAutomationType' => 'TEXT_ASSET_AUTOMATION', 'assetAutomationStatus' => 'OPTED_OUT'],
                ],
            ], 'campaignBudget' => ['amountMicros' => '60000000']],
            'groups' => [['assetGroup' => ['resourceName' => self::GROUP, 'status' => 'ENABLED',
                'finalUrls' => ['https://example.test/management'], 'adStrength' => 'POOR']]],
        ]);
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);

        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($google);
        $service = $this->createPartialMock(RefreshPerformanceMaxAssets::class, ['links']);
        $service->method('links')->with(self::ACCOUNT, self::CAMPAIGN, self::GROUP)->willReturn($links, $after ?? $links);
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($service, $client);
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($service, $strategy->campaign->customer);

        return $service;
    }

    public function test_refresh_validates_a_single_assets_only_request_with_distinct_long_text_portrait_and_video(): void
    {
        $strategy = $this->strategy();
        $copy = $strategy->adCopies()->create([
            'platform' => 'Google Ads', 'should_deploy' => true,
            'headlines' => array_map(fn (int $number) => "Headline {$number}", range(1, 11)),
            'long_headlines' => ['Build a managed search campaign with an Australian team', 'Get help tracking sign-ups across your paid search campaigns'],
            'descriptions' => ['Plan your ads with our team.', 'Get help measuring sales and sign-ups.', 'View your website campaign reports.', 'Manage your ad budget in one place.'],
        ]);
        config(['filesystems.disks.s3.bucket' => null]);
        $disk = Storage::fake('public');
        ob_start();
        imagepng(imagecreatetruecolor(960, 1200));
        $disk->put('portrait.png', (string) ob_get_clean());
        $image = ImageCollateral::create(['campaign_id' => $strategy->campaign_id, 'strategy_id' => $strategy->id,
            'platform' => 'Google Ads', 's3_path' => 'portrait.png', 'cloudfront_url' => '/portrait.png', 'is_active' => true, 'should_deploy' => true]);
        $video = VideoCollateral::create(['campaign_id' => $strategy->campaign_id, 'strategy_id' => $strategy->id,
            'platform' => 'Google Ads', 'status' => 'completed', 'script' => 'Reviewed voiceover.', 'youtube_video_id' => 'a1B2c3D4e5F', 'is_active' => true, 'should_deploy' => true]);

        $google = $this->createMock(GoogleAdsServiceClient::class);
        $google->expects($this->once())->method('mutate')->willReturnCallback(function (MutateGoogleAdsRequest $request): MutateGoogleAdsResponse {
            $this->assertSame(self::ACCOUNT, $request->getCustomerId());
            $this->assertFalse($request->getPartialFailure());
            $this->assertTrue($request->getValidateOnly());
            $createdAssets = [];
            $linked = [];
            $sawLink = false;

            foreach ($request->getMutateOperations() as $operation) {
                if ($created = $operation->getAssetOperation()?->getCreate()) {
                    $this->assertFalse($sawLink, 'All asset creations must precede link operations.');
                    $createdAssets[$created->getResourceName()] = $created;

                    continue;
                }
                $sawLink = true;
                $groupOperation = $operation->getAssetGroupAssetOperation();
                $this->assertNotNull($groupOperation);
                if ($groupOperation->getRemove()) {
                    $this->assertSame('customers/1234567890/assetGroupAssets/789~123~LONG_HEADLINE', $groupOperation->getRemove());

                    continue;
                }
                $link = $groupOperation->getCreate();
                $this->assertSame(self::GROUP, $link->getAssetGroup());
                $this->assertArrayHasKey($link->getAsset(), $createdAssets);
                $linked[$link->getFieldType()][] = $createdAssets[$link->getAsset()];
            }

            $this->assertCount(11, $linked[AssetFieldType::HEADLINE]);
            $this->assertCount(2, $linked[AssetFieldType::LONG_HEADLINE]);
            $this->assertCount(4, $linked[AssetFieldType::DESCRIPTION]);
            foreach ($linked[AssetFieldType::LONG_HEADLINE] as $headline) {
                $this->assertGreaterThan(30, mb_strlen($headline->getTextAsset()->getText()));
            }
            $this->assertCount(1, $linked[AssetFieldType::PORTRAIT_MARKETING_IMAGE]);
            $this->assertCount(1, $linked[AssetFieldType::YOUTUBE_VIDEO]);

            return new MutateGoogleAdsResponse;
        });
        $service = $this->service($strategy, $google, links: [
            ['field' => AssetFieldType::LONG_HEADLINE, 'resource' => 'customers/1234567890/assetGroupAssets/789~123~LONG_HEADLINE', 'key' => 'existing short line', 'advertiser' => true],
            ['field' => AssetFieldType::LOGO, 'resource' => 'customers/1234567890/assetGroupAssets/789~124~LOGO', 'key' => 'existing logo', 'advertiser' => true],
        ]);

        $preview = $service->refresh($strategy, $copy->id, [$image->id], [$video->id]);
        $this->assertTrue($preview['validate_only']);
        $this->assertSame(['LONG_HEADLINE' => 1], $preview['remove']);
        $this->assertSame('POOR', $preview['ad_strength']);
    }

    public function test_refresh_refuses_to_mutate_a_paused_platform_campaign(): void
    {
        $strategy = $this->strategy();
        $google = $this->createMock(GoogleAdsServiceClient::class);
        $google->expects($this->never())->method('mutate');
        $service = $this->service($strategy, $google, 'PAUSED');

        $this->expectExceptionMessage('The live Performance Max configuration differs');
        $service->refresh($strategy, 1, apply: true);
    }

    public function test_live_refresh_requires_the_exact_customer_approval(): void
    {
        $strategy = $this->strategy();
        $google = $this->createMock(GoogleAdsServiceClient::class);
        $google->expects($this->never())->method('mutate');
        $service = $this->service($strategy, $google);

        $this->expectExceptionMessage('Approve the exact creative selection');
        $service->refresh($strategy, 1, apply: true);
    }

    public function test_retirement_previews_only_one_known_image_association_and_requires_an_eligible_survivor(): void
    {
        $strategy = $this->strategy();
        $prefix = 'customers/1234567890/assetGroupAssets/789~';
        $old = $prefix.'123~PORTRAIT_MARKETING_IMAGE';
        $links = [
            ['field' => AssetFieldType::PORTRAIT_MARKETING_IMAGE, 'resource' => $old, 'key' => 'rejected portrait', 'advertiser' => true, 'eligible' => true],
            ['field' => AssetFieldType::PORTRAIT_MARKETING_IMAGE, 'resource' => $prefix.'124~PORTRAIT_MARKETING_IMAGE', 'key' => 'original portrait', 'advertiser' => true, 'eligible' => true],
        ];
        $google = $this->createMock(GoogleAdsServiceClient::class);
        $google->expects($this->once())->method('mutate')->willReturnCallback(function (MutateGoogleAdsRequest $request) use ($old) {
            $this->assertTrue($request->getValidateOnly());
            $this->assertCount(1, $request->getMutateOperations());
            $this->assertSame($old, $request->getMutateOperations()[0]->getAssetGroupAssetOperation()->getRemove());

            return new MutateGoogleAdsResponse;
        });
        $this->assertSame(['PORTRAIT_MARKETING_IMAGE' => 1], $this->service($strategy, $google, links: $links)->refresh($strategy, retireImageLinks: [$old])['remove']);
        $links[1]['eligible'] = false;
        $this->expectExceptionMessage('Wait for an eligible alternative');
        $this->service($strategy, $google, links: $links)->refresh($strategy, retireImageLinks: [$old]);
    }

    private function queuedRetirement(Strategy $strategy, string $old, array $logoHashes = []): RetireReviewedPerformanceMaxImages
    {
        $strategy->campaign->customer->update(['google_ads_link_status' => 'active']);
        $fingerprint = app(CreativeRelease::class)->selection($strategy)['fingerprint'];
        $strategy->update(['creative_release_review' => ['status' => 'passed', 'fingerprint' => $fingerprint],
            'creative_approval' => ['fingerprint' => $fingerprint, 'copy_ids' => [], 'image_ids' => array_keys($logoHashes),
                'video_ids' => [], 'image_hashes' => $logoHashes, 'logo_hashes' => $logoHashes]]);
        $service = app(RefreshPerformanceMaxAssets::class, ['customer' => $strategy->campaign->customer]);
        $service->retireAfterReview($strategy, [$old, $old]);
        $service->retireAfterReview($strategy, [$old]);
        Queue::assertPushed(RetireReviewedPerformanceMaxImages::class, 1);

        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue);

        return $queue->pushed(RetireReviewedPerformanceMaxImages::class)->sole();
    }

    public function test_queued_retirement_waits_for_same_role_then_removes_only_selected_image_and_readback_is_idempotent(): void
    {
        $strategy = $this->strategy();
        $prefix = 'customers/1234567890/assetGroupAssets/789~';
        $old = $prefix.'123~PORTRAIT_MARKETING_IMAGE';
        $links = [
            ['field' => AssetFieldType::PORTRAIT_MARKETING_IMAGE, 'resource' => $old, 'key' => 'rejected', 'advertiser' => true, 'eligible' => true],
            ['field' => AssetFieldType::PORTRAIT_MARKETING_IMAGE, 'resource' => $prefix.'124~PORTRAIT_MARKETING_IMAGE', 'key' => 'original', 'advertiser' => true, 'eligible' => false, 'pending' => true],
        ];
        $job = $this->queuedRetirement($strategy, $old);
        $google = $this->createMock(GoogleAdsServiceClient::class);
        $google->expects($this->once())->method('mutate')->willReturnCallback(function (MutateGoogleAdsRequest $request) use ($old) {
            $this->assertFalse($request->getValidateOnly());
            $this->assertCount(1, $request->getMutateOperations());
            $this->assertSame($old, $request->getMutateOperations()[0]->getAssetGroupAssetOperation()->getRemove());

            return new MutateGoogleAdsResponse;
        });
        $service = $this->service($strategy, $google, links: $links);
        $this->app->bind(RefreshPerformanceMaxAssets::class, fn () => $service);
        $job->withFakeQueueInteractions()->handle();
        $job->assertReleased(1800);
        $this->assertFalse(AgentActivity::where('action', 'retire_pmax_images')->where('campaign_id', $strategy->campaign_id)->exists());

        $links[1]['pending'] = false;
        $links[1]['eligible'] = true;
        $service = $this->service($strategy, $google, links: $links, after: [$links[1]]);
        $this->app->bind(RefreshPerformanceMaxAssets::class, fn () => $service);
        $job->withFakeQueueInteractions()->handle();
        $job->assertNotReleased();
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $strategy->campaign_id, 'action' => 'retire_pmax_images', 'status' => 'completed']);

        $service = $this->service($strategy, $google, links: [$links[1]]);
        $this->app->bind(RefreshPerformanceMaxAssets::class, fn () => $service);
        $job->withFakeQueueInteractions()->handle();
        $job->assertNotReleased();
        $this->assertSame(1, AgentActivity::where('action', 'retire_pmax_images')->where('campaign_id', $strategy->campaign_id)->count());
    }

    public function test_old_logo_waits_for_the_exact_approved_logo_then_retires_even_if_unapproved_logos_are_eligible(): void
    {
        $strategy = $this->strategy();
        $digest = hash('sha256', 'reviewed business logo bytes');
        $logo = ImageCollateral::create(['campaign_id' => $strategy->campaign_id, 'strategy_id' => $strategy->id,
            'platform' => 'Google Ads', 's3_path' => 'reviewed-logo.png', 'cloudfront_url' => '/reviewed-logo.png', 'layout' => 'logo',
            'source' => 'uploaded', 'generation_metadata' => ['provider' => 'customer_upload', 'role' => 'LOGO', 'sha256' => $digest],
            'is_active' => true, 'should_deploy' => true]);
        $prefix = 'customers/1234567890/assetGroupAssets/789~';
        $old = $prefix.'123~LOGO';
        $links = [
            ['field' => AssetFieldType::LOGO, 'resource' => $old, 'key' => 'photo', 'advertiser' => true, 'eligible' => true],
            ['field' => AssetFieldType::LOGO, 'resource' => $prefix.'124~LOGO', 'key' => 'PMax image '.substr($digest, 0, 32), 'advertiser' => true, 'eligible' => false, 'pending' => true],
            ['field' => AssetFieldType::LOGO, 'resource' => $prefix.'125~LOGO', 'key' => 'unapproved logo', 'advertiser' => true, 'eligible' => true],
        ];
        $job = $this->queuedRetirement($strategy, $old, [$logo->id => $digest]);
        $google = $this->createMock(GoogleAdsServiceClient::class);
        $google->expects($this->once())->method('mutate')->willReturnCallback(function (MutateGoogleAdsRequest $request) use ($old) {
            $this->assertFalse($request->getValidateOnly());
            $this->assertCount(1, $request->getMutateOperations());
            $this->assertSame($old, $request->getMutateOperations()[0]->getAssetGroupAssetOperation()->getRemove());

            return new MutateGoogleAdsResponse;
        });
        $service = $this->service($strategy, $google, links: $links);
        $this->app->bind(RefreshPerformanceMaxAssets::class, fn () => $service);
        $job->withFakeQueueInteractions()->handle();
        $job->assertReleased(1800);
        $links[1]['pending'] = false;
        $links[1]['eligible'] = true;
        $service = $this->service($strategy, $google, links: $links, after: array_slice($links, 1));
        $this->app->bind(RefreshPerformanceMaxAssets::class, fn () => $service);
        $job->withFakeQueueInteractions()->handle();
        $job->assertNotReleased();
    }

    public function test_unapproved_logo_or_an_automatically_created_survivor_cannot_keep_a_job_waiting(): void
    {
        $strategy = $this->strategy();
        $prefix = 'customers/1234567890/assetGroupAssets/789~';
        $old = $prefix.'123~LOGO';
        $links = [
            ['field' => AssetFieldType::LOGO, 'resource' => $old, 'key' => 'old', 'advertiser' => true, 'eligible' => true],
            ['field' => AssetFieldType::LOGO, 'resource' => $prefix.'124~LOGO', 'key' => 'unapproved', 'advertiser' => true, 'eligible' => false, 'pending' => true],
        ];
        $job = $this->queuedRetirement($strategy, $old)->withFakeQueueInteractions();
        $google = $this->createMock(GoogleAdsServiceClient::class);
        $google->expects($this->never())->method('mutate');
        $service = $this->service($strategy, $google, links: $links);
        $this->app->bind(RefreshPerformanceMaxAssets::class, fn () => $service);
        try {
            $job->handle();
            $this->fail('Missing approved logo should stop retirement.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no qualifying replacement pending', $e->getMessage());
            $job->failed($e);
        }
        $job->assertNotReleased();
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $strategy->campaign_id, 'action' => 'retire_pmax_images', 'status' => 'failed']);

        $old = $prefix.'123~MARKETING_IMAGE';
        $links[0] = ['field' => AssetFieldType::MARKETING_IMAGE, 'resource' => $old, 'key' => 'old', 'advertiser' => true, 'eligible' => true];
        $links[1] = ['field' => AssetFieldType::MARKETING_IMAGE, 'resource' => $prefix.'124~MARKETING_IMAGE', 'key' => 'generated', 'advertiser' => false, 'eligible' => true];
        $this->expectExceptionMessage('no qualifying replacement pending');
        $this->service($strategy, $google, links: $links)->refresh($strategy, retireImageLinks: [$old]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('retirementStops')]
    public function test_stale_or_held_retirements_stop_before_reading_or_mutating_google(string $change, string $message): void
    {
        $strategy = $this->strategy();
        $customerId = $strategy->campaign->customer_id;
        $job = $this->queuedRetirement($strategy, 'customers/1234567890/assetGroupAssets/789~123~LOGO')->withFakeQueueInteractions();
        $stage = $strategy->execution_result;
        $stage['metadata']['google_pmax_staging']['asset_group'] = 'customers/1234567890/assetGroups/790';
        match ($change) {
            'owner' => $strategy->campaign->update(['customer_id' => Customer::factory()->create()->id]),
            'group' => $strategy->update(['execution_result' => $stage]),
            'release' => $strategy->update(['creative_approval' => ['fingerprint' => str_repeat('a', 64)]]),
            'pause' => $strategy->campaign->update(['spend_safety_hold' => ['reason' => 'paused']]),
            'tenant_hold' => Feature::for($strategy->campaign->customer)->deactivate(AutoHealing::class),
            'timeout' => $this->travel(6)->days(),
            default => throw new \InvalidArgumentException("Unknown retirement scenario: {$change}"),
        };
        $reader = $this->createMock(ReadCampaignConfiguration::class);
        $reader->expects($this->never())->method('performanceMax');
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
        try {
            $job->handle();
            $this->fail('The retirement must stop.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $job->assertNotReleased();
            if ($change === 'owner') {
                $job->failed($exception);
                $this->assertDatabaseHas('agent_activities', ['action' => 'retire_pmax_images',
                    'status' => 'failed', 'customer_id' => $customerId, 'campaign_id' => null]);
            }
        }
    }

    public static function retirementStops(): array
    {
        return [['owner', 'owner of the queued'], ['group', 'asset group changed'], ['release', 'approved release'],
            ['pause', 'spend safety hold'], ['tenant_hold', 'auto healing disabled'], ['timeout', 'window expired']];
    }
}
