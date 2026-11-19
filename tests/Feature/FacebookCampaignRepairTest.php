<?php

namespace Tests\Feature;

use App\Jobs\FetchFacebookAdsPerformanceData;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Services\Agents\Optimization\MetricsFetcher;
use App\Services\Creative\CreativeRelease;
use App\Services\FacebookAds\FacebookAudienceTargeting;
use App\Services\FacebookAds\FacebookCampaignMetrics;
use App\Services\FacebookAds\FacebookCreativeCopy;
use App\Services\FacebookAds\RefreshFacebookAdCopy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FacebookCampaignRepairTest extends TestCase
{
    use DatabaseTransactions;

    private function strategy(): Strategy
    {
        config(['services.facebook.system_user_token' => 'test-token']);
        $customer = Customer::factory()->create(['facebook_bm_owned' => true, 'facebook_ads_account_id' => '123']);
        $campaign = Campaign::factory()->for($customer)->create(['facebook_ads_campaign_id' => '456']);
        $strategy = Strategy::factory()->for($campaign)->create([
            'platform' => 'Facebook Ads', 'campaign_type' => 'display',
            'signed_off_at' => now(), 'daily_budget' => 40,
            'conversion_goals' => ['primary_goal' => 'Sign-up'],
            'execution_result' => ['platform_ids' => ['campaign' => '456', 'ad_set' => '789', 'image_ad_1' => '111', 'video_ad_2' => '222']],
        ]);
        $strategy->forceFill(['facebook_campaign_id' => '456', 'facebook_adset_id' => '789', 'facebook_ad_id' => '111'])->save();
        $strategy->adCopies()->create(['platform' => 'Facebook', 'should_deploy' => true,
            'headlines' => ['First headline', 'Second headline'], 'descriptions' => ['First approved body', 'Second approved body']]);

        return $strategy;
    }

    public function test_signed_copy_variants_only_combine_text_within_the_same_persona(): void
    {
        $strategy = $this->strategy();
        $strategy->adCopies()->create(['platform' => 'Facebook', 'should_deploy' => false,
            'headlines' => ['Excluded'], 'descriptions' => ['Excluded']]);
        $strategy->adCopies()->create(['platform' => 'Facebook', 'should_deploy' => true,
            'headlines' => ['Other persona'], 'descriptions' => ['Other body']]);
        $this->assertSame([
            ['headline' => 'First headline', 'body' => 'First approved body'],
            ['headline' => 'Other persona', 'body' => 'Other body'],
            ['headline' => 'Second headline', 'body' => 'Second approved body'],
            ['headline' => 'First headline', 'body' => 'Second approved body'],
            ['headline' => 'Second headline', 'body' => 'First approved body'],
        ], FacebookCreativeCopy::variations($strategy));
    }

    public function test_research_does_not_mistake_generated_persona_for_a_hard_limit(): void
    {
        $strategy = $this->strategy();
        $config = $strategy->targetingConfig()->create(['age_min' => 30, 'age_max' => 55, 'genders' => ['male']]);
        $targeting = FacebookAudienceTargeting::forReview($strategy, ['AU']);
        $this->assertSame(['countries' => ['AU']], $targeting['geo_locations']);
        $this->assertSame([18, 65, 1], [$targeting['age_min'], $targeting['age_max'], $targeting['targeting_automation']['advantage_audience']]);
        $this->assertArrayNotHasKey('genders', $targeting);
        $config->update(['facebook_options' => ['age_min' => 30, 'age_max' => 55, 'genders' => [1], 'publisher_platforms' => ['instagram']]]);
        $targeting = FacebookAudienceTargeting::forReview($strategy->fresh(), ['AU']);
        $this->assertSame([30, 55, 0], [$targeting['age_min'], $targeting['age_max'], $targeting['targeting_automation']['advantage_audience']]);
        $this->assertSame([1], $targeting['genders']);
        $this->assertSame(['instagram'], $targeting['publisher_platforms']);
    }

    public function test_sync_uses_link_cpc_and_website_registrations_without_double_counting(): void
    {
        $strategy = $this->strategy();
        $date = today()->subDay()->toDateString();
        $actions = [
            ['action_type' => 'complete_registration', 'value' => '3'],
            ['action_type' => 'offsite_conversion.fb_pixel_complete_registration', 'value' => '2'],
            ['action_type' => 'purchase', 'value' => '17'],
        ];
        $this->assertSame('complete_registration', FacebookCampaignMetrics::actionType(FacebookCampaignMetrics::strategy($strategy->campaign)));
        Http::fake(['graph.facebook.com/*/456/insights*' => Http::response(['data' => [
            ['date_start' => $date, 'impressions' => 600, 'clicks' => 11, 'inline_link_clicks' => 2, 'spend' => '30.00', 'cpc' => '2.72', 'actions' => $actions],
            ['date_start' => today()->toDateString(), 'impressions' => 200, 'clicks' => 4, 'spend' => '8.00'],
        ]])]);
        (new FetchFacebookAdsPerformanceData($strategy->campaign))->handle();
        $first = FacebookAdsPerformanceData::where('campaign_id', $strategy->campaign_id)->whereDate('date', $date)->sole();
        $this->assertEquals(2, $first->conversions);
        $this->assertEquals(2, $first->clicks);
        $this->assertEquals(15, $first->cpc);
        $this->assertEquals(15, $first->cpa);
        $this->assertEquals(0, FacebookAdsPerformanceData::where('campaign_id', $strategy->campaign_id)->whereDate('date', today())->sole()->clicks);
    }

    public function test_optimizer_reads_period_link_clicks_and_primary_conversions_for_disjoint_months(): void
    {
        $campaign = $this->strategy()->campaign;
        $campaign->forceFill(['google_ads_campaign_id' => null]);
        $seen = [];
        Http::fake(function (Request $request) use (&$seen) {
            $this->assertStringContainsString('/456/insights', $request->url());
            $this->assertSame('all_days', $request['time_increment']);
            $seen[] = json_decode($request['time_range'], true);

            return Http::response(['data' => [[
                'impressions' => '1000', 'inline_link_clicks' => '3', 'clicks' => '9', 'spend' => '60.00',
                'frequency' => '2.5', 'reach' => '400',
                'actions' => [['action_type' => 'offsite_conversion.fb_pixel_complete_registration', 'value' => '2'],
                    ['action_type' => 'purchase', 'value' => '5']],
            ]]]);
        });
        $fetcher = app(MetricsFetcher::class);
        $current = $fetcher->fetchCurrent($campaign);
        $this->assertSame(3, $current['clicks']);
        $this->assertSame(2.0, $current['conversions']);
        $this->assertEquals(20_000_000, $current['average_cpc']);
        $this->assertEquals(30_000_000, $current['cost_per_conversion']);
        $this->assertSame(400, $current['reach']);
        $this->assertSame($current, $fetcher->fetchHistorical($campaign));
        $this->assertLessThan($seen[0]['since'], $seen[1]['until']);
    }

    public function test_refresh_swaps_only_the_selected_ad_and_retries_without_another_write(): void
    {
        $strategy = $this->strategy();
        $creative = [
            'id' => 'old', 'url_tags' => 'source=meta',
            'degrees_of_freedom_spec' => ['creative_features_spec' => []],
            'object_story_spec' => ['page_id' => '55', 'video_data' => [
                'video_id' => 'video77', 'image_hash' => 'thumb', 'image_url' => 'https://example.com/thumbnail.jpg',
                'title' => 'First headline', 'message' => 'Original', 'link_description' => 'Original',
                'call_to_action' => ['type' => 'LEARN_MORE', 'value' => ['link' => 'https://example.com/?tracking=keep']],
            ]],
        ];
        $replacement = null;
        Http::fake(function (Request $request) use (&$creative, &$replacement) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/222') && $request->method() === 'GET') {
                return Http::response(['id' => '222', 'account_id' => '123', 'campaign_id' => '456', 'adset_id' => '789',
                    'status' => 'ACTIVE', 'effective_status' => 'PENDING_REVIEW', 'name' => 'Original ad', 'creative' => $creative]);
            }
            if (str_ends_with($path, '/act_123/adcreatives') && $request->method() === 'POST') {
                $replacement = $request->data();
                $this->assertSame('source=meta', $replacement['url_tags']);
                $data = $replacement['object_story_spec']['video_data'];
                $this->assertSame('video77', $data['video_id']);
                $this->assertSame('thumb', $data['image_hash']);
                $this->assertSame('Second headline', $data['title']);
                $this->assertSame('Second approved body', $data['message']);
                $this->assertSame(['type' => 'SIGN_UP', 'value' => ['link' => 'https://example.com/?tracking=keep']], $data['call_to_action']);

                return Http::response(['id' => 'new']);
            }
            if (str_ends_with($path, '/222') && $request->method() === 'POST') {
                $this->assertSame(['creative', 'access_token'], array_keys($request->data()));
                $creative = ['id' => 'new', ...$replacement];

                return Http::response(['success' => true]);
            }
            $this->fail('Unexpected Meta endpoint');
        });
        $repair = new RefreshFacebookAdCopy($strategy->campaign->customer);
        $this->assertSame('planned', $repair->run($strategy, adIds: ['222'])[0]['status']);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        $this->assertSame('updated', $repair->run($strategy, apply: true, adIds: ['222'])[0]['status']);
        $this->assertSame('unchanged', $repair->run($strategy, apply: true, adIds: ['222'])[0]['status']);
        $this->assertSame(2, Http::recorded(fn (Request $request) => $request->method() === 'POST')->count());
    }

    private function approvedImage(Strategy $strategy): ImageCollateral
    {
        Storage::fake('public')->put('reviewed.png', 'approved file bytes');
        $image = ImageCollateral::create(['campaign_id' => $strategy->campaign_id, 'strategy_id' => $strategy->id,
            'is_active' => true, 'should_deploy' => true, 'platform' => 'Facebook', 's3_path' => 'reviewed.png', 'cloudfront_url' => '/reviewed.png']);
        $release = app(CreativeRelease::class);
        $fingerprint = $release->selection($strategy)['fingerprint'];
        $strategy->update(['creative_release_review' => ['status' => 'passed', 'fingerprint' => $fingerprint, 'issues' => [],
            'image_hashes' => [$image->id => hash('sha256', 'approved file bytes')]]]);
        $release->approve($strategy, $fingerprint);

        return $image;
    }

    public function test_reviewed_media_can_replace_a_video_in_place_with_its_original_page_destination_and_cta(): void
    {
        $strategy = $this->strategy();
        $image = $this->approvedImage($strategy);
        $story = ['page_id' => '55', 'instagram_user_id' => '44', 'video_data' => ['video_id' => 'video77',
            'call_to_action' => ['type' => 'LEARN_MORE', 'value' => ['link' => 'https://example.com/register?campaign=7']]]];
        $creative = ['id' => 'old', 'url_tags' => 'utm_source=facebook', 'object_story_spec' => $story];
        $replacement = null;
        Http::fake(function (Request $request) use (&$creative, &$replacement) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/222') && $request->method() === 'GET') {
                return Http::response(['id' => '222', 'account_id' => '123', 'campaign_id' => '456', 'adset_id' => '789',
                    'status' => 'ACTIVE', 'effective_status' => 'PENDING_REVIEW', 'name' => 'Video slot', 'creative' => $creative]);
            }
            if (str_ends_with($path, '/act_123/adimages') && $request->method() === 'POST') {
                $this->assertSame('approved file bytes', base64_decode($request['bytes'], true));

                return Http::response(['images' => ['bytes' => ['hash' => 'imagehash']]]);
            }
            if (str_ends_with($path, '/act_123/adcreatives') && $request->method() === 'POST') {
                $replacement = $request->data();
                $newStory = $replacement['object_story_spec'];
                $this->assertSame('55', $newStory['page_id']);
                $this->assertSame('44', $newStory['instagram_user_id']);
                $this->assertArrayNotHasKey('video_data', $newStory);
                $this->assertSame('imagehash', $newStory['link_data']['image_hash']);
                $this->assertSame('https://example.com/register?campaign=7', $newStory['link_data']['link']);
                $this->assertSame('LEARN_MORE', $newStory['link_data']['call_to_action']['type']);
                $this->assertSame('utm_source=facebook', $replacement['url_tags']);

                return Http::response(['id' => 'new']);
            }
            if (str_ends_with($path, '/222') && $request->method() === 'POST') {
                $this->assertSame(['creative', 'access_token'], array_keys($request->data()));
                $creative = ['id' => 'new', ...$replacement];

                return Http::response(['success' => true]);
            }
            $this->fail('Unexpected Meta endpoint');
        });
        $repair = new RefreshFacebookAdCopy($strategy->campaign->customer);
        $map = ['222' => $image->id];
        $this->assertSame('planned', $repair->run($strategy, imageIdsByAd: $map)[0]['status']);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        $row = $repair->run($strategy, apply: true, imageIdsByAd: $map)[0];
        $this->assertSame('imagehash', $row['image_hash']);
        $this->assertSame('PENDING_REVIEW', $row['effective_status']);
        $this->assertSame('updated', $row['status']);
        $this->assertSame('unchanged', $repair->run($strategy, apply: true, imageIdsByAd: $map)[0]['status']);
        $this->assertSame(3, Http::recorded(fn (Request $request) => $request->method() === 'POST')->count());
    }

    public function test_replacing_media_rechecks_approved_file_bytes_before_any_graph_operation(): void
    {
        $strategy = $this->strategy();
        $image = $this->approvedImage($strategy);
        Storage::disk('public')->put('reviewed.png', 'changed after approval');
        Http::fake();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has changed or cannot be read');
        try {
            (new RefreshFacebookAdCopy($strategy->campaign->customer))->run($strategy, apply: true, imageIdsByAd: ['111' => $image->id]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_refresh_requires_reapproval_if_legacy_copy_changed_after_signoff(): void
    {
        $strategy = $this->strategy();
        $strategy->update(['signed_off_at' => now()->subDay()]);
        Http::fake();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Approve the current creative selection');
        try {
            (new RefreshFacebookAdCopy($strategy->campaign->customer))->run($strategy, apply: true);
        } finally {
            Http::assertNothingSent();
        }
    }
}
