<?php

namespace Tests\Feature;

use App\Features\AutoHealing;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CustomerPage;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Campaigns\AdvertisingEvidence;
use App\Services\GeminiService;
use App\Services\GoogleAds\AccountStructureService;
use App\Services\GoogleAds\GoogleAdRankRepair;
use App\Services\GoogleAds\GoogleAdStrengthRepair;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Services\Client\AdGroupAdServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\AssetServiceClient;
use Google\Ads\GoogleAds\V26\Services\Client\CampaignAssetServiceClient;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAdResult;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAdsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateAssetResult;
use Google\Ads\GoogleAds\V26\Services\MutateAssetsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignAssetResult;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignAssetsResponse;
use Google\ApiCore\ApiException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class GoogleAdRankRepairTest extends TestCase
{
    use DatabaseTransactions;

    private ?Customer $fixtureCustomer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Cache::flush();
        Http::fake();
    }

    private function fixture(bool $trial = true): array
    {
        $customer = $this->fixtureCustomer ??= Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'service_type' => 'managed',
            'google_ads_link_status' => 'active', 'is_sandbox' => false, 'website' => 'https://example.com']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active',
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/61', 'daily_budget' => '10.00',
            'approved_daily_budget' => '10.00', 'budget_confirmed_at' => now(),
            'spend_guardrails' => $trial ? ['enabled' => true, 'allow_ad_rank_repairs' => true,
                'baseline_cost_micros' => 20_000_000, 'max_spend_micros' => 50_000_000,
                'max_daily_budget_micros' => 10_000_000, 'max_cpc_bid_micros' => 3_000_000,
                'currency_code' => 'AUD', 'account_timezone' => 'Australia/Sydney'] : null]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'google_ads_ad_group_id' => 'customers/1234567890/adGroups/7', 'signed_off_at' => now(),
            'execution_result' => ['metadata' => ['google_search_baseline' => ['ads' => [
                ['resource' => RankRepairFixture::OLD, 'headlines' => ['Old approved headline'], 'descriptions' => ['Old description']],
            ], 'asset_resources' => []]]]]);
        CustomerPage::firstOrCreate(['customer_id' => $customer->id, 'url' => 'https://example.com'], ['title' => 'Google Ads Management',
            'content' => 'Google Ads management services. Review your ads before launch. Advertising spend is additional.', 'page_type' => 'homepage']);
        Feature::for($customer)->activate(AutoHealing::class);
        $service = new RankRepairFixture($this->createMock(GeminiService::class), new AdvertisingEvidence);

        return [$campaign->load('customer'), $strategy, $service];
    }

    private function limited(): array
    {
        return ['status' => 'rank_limited'];
    }

    public function test_replacement_is_staged_paused_and_working_approved_ad_keeps_serving(): void
    {
        [$campaign, , $service] = $this->fixture();
        $before = $campaign->fresh()->spend_guardrails;
        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('verifying', $result['status']);
        $this->assertSame('awaiting_policy_review', $result['metadata']['phase']);
        $this->assertSame(1, $result['metadata']['actions_taken']);
        $this->assertFalse($result['metadata']['rank_recovery_verified']);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
        $this->assertSame('PAUSED', $service->rows[RankRepairFixture::NEW]['adGroupAd']['status']);
        $this->assertSame($before, $campaign->fresh()->spend_guardrails, 'No baseline, cap, threshold or permission is reset.');
        $this->assertSame('10.00', $campaign->fresh()->daily_budget);
        Http::assertNothingSent();
    }

    public function test_waiting_for_policy_does_not_activate_pause_or_duplicate_anything(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->repair($campaign, $service->snapshot, $this->limited());
        $service->writes = [];
        $snapshot = $service->snapshot;
        $snapshot['rank_evidence']['status'] = 'collecting_evidence';

        $result = $service->repair($campaign, $snapshot, ['status' => 'collecting_evidence']);

        $this->assertSame('verifying', $result['status']);
        $this->assertSame(0, $result['metadata']['actions_taken']);
        $this->assertSame([], $service->writes);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
    }

    public function test_policy_approval_enables_and_verifies_new_before_pausing_old_but_does_not_claim_rank_recovery(): void
    {
        [$campaign, $strategy, $service] = $this->fixture();
        $service->repair($campaign, $service->snapshot, $this->limited());
        $service->approve();
        $service->writes = [];

        $result = $service->repair($campaign, $service->snapshot, ['status' => 'collecting_evidence']);

        $this->assertSame('verifying', $result['status']);
        $this->assertSame('observing_delivery', $result['metadata']['phase']);
        $this->assertTrue($result['metadata']['creative_replacement_verified']);
        $this->assertFalse($result['metadata']['rank_recovery_verified']);
        $this->assertSame(2, $result['metadata']['actions_taken']);
        $this->assertSame([['status', RankRepairFixture::NEW, 'ENABLED'], ['status', RankRepairFixture::OLD, 'PAUSED']], $service->writes);
        $this->assertSame(RankRepairFixture::NEW, $strategy->fresh()->execution_result['metadata']['google_search_baseline']['ads'][0]['resource']);
    }

    public function test_provisional_rank_and_weak_ad_strength_cannot_trigger_automatic_writes(): void
    {
        [$campaign, , $service] = $this->fixture();
        $snapshot = $service->snapshot;
        $snapshot['rank_evidence']['status'] = 'collecting_evidence';

        $result = $service->repair($campaign, $snapshot, ['status' => 'rank_limited']);

        $this->assertSame('no_op', $result['status']);
        $this->assertSame([], $service->writes);
        $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->count());
    }

    public function test_authorized_owner_request_can_stage_during_provisional_rank_without_claiming_rank_causality(): void
    {
        [$campaign, , $service] = $this->fixture();
        $owner = User::factory()->create();
        $campaign->customer->users()->attach($owner);
        $trial = $campaign->spend_guardrails;
        $trial['ad_rank_repair_authorization'] = ['owner_requested' => true, 'customer_id' => $campaign->customer_id,
            'google_customer_id' => '1234567890', 'campaign_resource' => $campaign->googleAdsResourceName(),
            'authorized_at' => now()->toIso8601String(), 'requested_by_user_id' => $owner->id, 'reason' => 'Review and improve current creative.'];
        $campaign->forceFill(['spend_guardrails' => $trial])->save();
        $snapshot = $service->snapshot;
        $snapshot['rank_evidence']['status'] = 'collecting_evidence';

        $result = $service->repair($campaign, $snapshot, ['owner_requested' => true]);

        $this->assertSame('verifying', $result['status']);
        $this->assertFalse($result['metadata']['rank_causality_verified']);
        $this->assertCount(1, $service->writes);
    }

    public function test_unscoped_or_expired_owner_request_cannot_bypass_rank_proof(): void
    {
        [$campaign, , $service] = $this->fixture();
        $owner = User::factory()->create();
        $campaign->customer->users()->attach($owner);
        foreach (['foreign' => ['google_customer_id' => '9999999999'], 'expired' => ['authorized_at' => now()->subDays(8)->toIso8601String()]] as $change) {
            $trial = $campaign->spend_guardrails;
            $trial['ad_rank_repair_authorization'] = array_replace(['owner_requested' => true, 'customer_id' => $campaign->customer_id,
                'google_customer_id' => '1234567890', 'campaign_resource' => $campaign->googleAdsResourceName(),
                'authorized_at' => now()->toIso8601String(), 'requested_by_user_id' => $owner->id, 'reason' => 'Repair'], $change);
            $campaign->forceFill(['spend_guardrails' => $trial])->save();

            $this->assertSame('held', $service->repair($campaign, $service->snapshot, ['owner_requested' => true])['status']);
        }
        $this->assertSame([], $service->writes);
    }

    public function test_revoked_permission_owner_pause_or_consumed_trial_budget_holds_pending_replacement(): void
    {
        foreach (['permission', 'pause', 'threshold'] as $condition) {
            [$campaign, , $service] = $this->fixture();
            $service->repair($campaign, $service->snapshot, $this->limited());
            $service->approve();
            if ($condition === 'permission') {
                $trial = $campaign->spend_guardrails;
                $trial['allow_ad_rank_repairs'] = false;
                $campaign->forceFill(['spend_guardrails' => $trial])->save();
            } elseif ($condition === 'pause') {
                $campaign->update(['status' => 'paused']);
            } else {
                $service->spendData['cost_micros'] = 70_000_000;
            }
            $service->writes = [];

            $result = $service->repair($campaign, $service->snapshot, $this->limited());

            $this->assertSame('held', $result['status']);
            $this->assertSame([], $service->writes);
            $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
        }
    }

    public function test_currency_budget_cap_or_account_mismatch_never_authorizes_stage(): void
    {
        foreach (['currency_code' => 'USD', 'daily_budget_micros' => 11_000_000, 'cpc_bid_ceiling_micros' => 4_000_000] as $field => $value) {
            [$campaign, , $service] = $this->fixture();
            $service->spendData[$field] = $value;
            $result = $service->repair($campaign, $service->snapshot, $this->limited());
            $this->assertSame('held', $result['status']);
            $this->assertSame([], $service->writes);
        }
    }

    public function test_unknown_pins_are_preserved_and_explicit_paths_keep_the_exact_final_url(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->rows[RankRepairFixture::OLD]['adGroupAd']['ad']['responsiveSearchAd']['headlines'][0]['pinnedField'] = 'HEADLINE_1';
        $service->proposal['display_paths'] = ['google-ads', 'management'];

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('verifying', $result['status']);
        $rsa = $service->rows[RankRepairFixture::NEW]['adGroupAd']['ad']['responsiveSearchAd'];
        $this->assertSame(['text' => 'Old approved headline', 'pinnedField' => 'HEADLINE_1'], $rsa['headlines'][0]);
        $this->assertSame('google-ads', $rsa['path1']);
        $this->assertSame(['https://example.com?utm_campaign=61'], $service->rows[RankRepairFixture::NEW]['adGroupAd']['ad']['finalUrls']);
    }

    public function test_failed_old_pause_readback_restores_old_before_pausing_replacement(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->repair($campaign, $service->snapshot, $this->limited());
        $service->approve();
        $service->hideOriginalAfterPause = true;
        $service->writes = [];

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('needs_review', $result['status']);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
        $this->assertSame('PAUSED', $service->rows[RankRepairFixture::NEW]['adGroupAd']['status']);
        $this->assertSame([['status', RankRepairFixture::NEW, 'ENABLED'], ['status', RankRepairFixture::OLD, 'PAUSED'],
            ['status', RankRepairFixture::OLD, 'ENABLED'], ['status', RankRepairFixture::NEW, 'PAUSED']], $service->writes);
    }

    public function test_validate_only_does_not_create_live_state_or_persist_a_repair_proof(): void
    {
        [$campaign, , $service] = $this->fixture();
        $result = $service->dryRun()->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('no_op', $result['status']);
        $this->assertTrue($result['metadata']['validate_only']);
        $this->assertSame(0, $result['metadata']['actions_taken']);
        $this->assertArrayNotHasKey(RankRepairFixture::NEW, $service->rows);
        $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->count());
    }

    public function test_full_reviewed_asset_plan_is_durable_before_the_first_google_write(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->oldCallout();

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('verifying', $result['status']);
        $this->assertSame('staging_assets', $service->detailsBeforeFirstWrite['phase']);
        $this->assertCount(1, $service->detailsBeforeFirstWrite['planned_assets']);
        $this->assertSame([], $service->detailsBeforeFirstWrite['assets']);
        $this->assertArrayNotHasKey('replacement_resource', $service->detailsBeforeFirstWrite);
        $attempt = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail();
        $this->assertSame('awaiting_policy_review', $attempt->details['phase']);
        $this->assertCount(1, $attempt->details['assets']);
    }

    public function test_interrupted_staging_never_activates_an_approved_partial_set_or_recreates_it(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->oldCallout();
        $service->repair($campaign, $service->snapshot, $this->limited());
        $attempt = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail();
        $details = $attempt->details;
        $details['phase'] = 'staging_assets';
        unset($details['assets'][0]['link_resource']);
        $attempt->update(['details' => $details]);
        $service->approve();
        $service->writes = [];

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('needs_review', $attempt->fresh()->status);
        $this->assertSame([], $service->writes);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
        $this->assertSame('PAUSED', $service->rows[RankRepairFixture::NEW]['adGroupAd']['status']);
    }

    public function test_two_asset_batches_resume_only_the_paused_acknowledged_prefix_without_recreating_the_rsa(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->proposal['callouts'] = ['Review Before Launch', 'Ad Spend Additional', 'Keyword Research', 'Campaign Monitoring'];

        $first = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('verifying', $first['status']);
        $this->assertSame('staging_assets', $first['metadata']['phase']);
        $attempt = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail();
        $this->assertCount(4, $attempt->details['planned_assets']);
        $this->assertCount(2, $attempt->details['assets']);
        $this->assertSame(5, $first['metadata']['actions_taken']);
        $this->assertCount(1, $service->detailsBeforeAssetCreate['assets'], 'The first asset intent is durable before its create call.');
        $this->assertArrayNotHasKey('asset_resource', $service->detailsBeforeAssetCreate['assets'][0]);
        $service->approve();
        $service->writes = [];

        $second = $service->repair($campaign, $service->snapshot, ['status' => 'collecting_evidence']);

        $this->assertSame('verifying', $second['status']);
        $this->assertSame('awaiting_policy_review', $second['metadata']['phase']);
        $this->assertSame(4, $second['metadata']['actions_taken']);
        $this->assertCount(4, $attempt->fresh()->details['assets']);
        $this->assertSame(1, $service->generationCalls);
        $this->assertNotContains(['create_paused_ad'], $service->writes);
        $this->assertNotContains(['status', RankRepairFixture::NEW, 'ENABLED'], $service->writes);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
        foreach ($service->assetRows as $row) {
            $this->assertSame('PAUSED', $row['campaignAsset']['status']);
        }
    }

    public function test_confirmed_read_quota_preserves_the_paused_prefix_and_resumes_only_after_the_server_cooldown(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->proposal['callouts'] = ['Review Before Launch', 'Ad Spend Additional', 'Keyword Research', 'Campaign Monitoring'];
        $service->repair($campaign, $service->snapshot, $this->limited());
        $attempt = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail();
        $before = $attempt->details;
        $service->quotaReadSource = 'inspect';
        $service->writes = [];

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('verifying', $result['status']);
        $this->assertSame('google_api_quota_cooldown', $result['metadata']['reason']);
        $this->assertSame('staging_assets', $result['metadata']['phase']);
        $this->assertSame(0, $result['metadata']['actions_taken']);
        $this->assertSame(now()->addSeconds(900)->toIso8601String(), $result['metadata']['next_check_at']);
        $this->assertSame($before['assets'], $attempt->fresh()->details['assets']);
        $this->assertSame($before['planned_assets'], $attempt->fresh()->details['planned_assets']);
        $this->assertSame($before['replacement_resource'], $attempt->fresh()->details['replacement_resource']);
        $this->assertSame('pending', $attempt->fresh()->status);
        $reads = $service->readCalls;
        $service->quotaReadSource = null;
        $this->travel(899)->seconds();

        $waiting = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('google_api_quota_cooldown', $waiting['metadata']['reason']);
        $this->assertSame($reads, $service->readCalls, 'No Google API request is made before the server deadline.');
        $this->assertSame([], $service->writes);
        $this->travel(2)->seconds();

        $resumed = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('awaiting_policy_review', $resumed['metadata']['phase']);
        $this->assertSame($attempt->id, $resumed['metadata']['attempt_id']);
        $this->assertSame($before['replacement_resource'], $resumed['metadata']['replacement_resource']);
        $this->assertSame(1, $service->generationCalls);
        $this->assertNotContains(['create_paused_ad'], $service->writes);
        $this->assertCount(4, $attempt->fresh()->details['assets']);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
    }

    public function test_read_quota_during_policy_verification_preserves_the_same_attempt_without_activating(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->oldCallout();
        $service->repair($campaign, $service->snapshot, $this->limited());
        $service->approve();
        $service->quotaReadSource = 'assets';
        $service->writes = [];

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('verifying', $result['status']);
        $this->assertSame('google_api_quota_cooldown', $result['metadata']['reason']);
        $this->assertSame('awaiting_policy_review', $result['metadata']['phase']);
        $this->assertSame([], $service->writes);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
        $this->assertSame('PAUSED', $service->rows[RankRepairFixture::NEW]['adGroupAd']['status']);
        $service->quotaReadSource = null;
        $this->travel(901)->seconds();

        $resumed = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('observing_delivery', $resumed['metadata']['phase']);
        $this->assertSame($result['metadata']['attempt_id'], $resumed['metadata']['attempt_id']);
        $this->assertSame(1, $service->generationCalls);
    }

    public function test_mutating_quota_is_not_classified_as_a_safe_read_retry_or_replayed(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->proposal['callouts'] = ['Review Before Launch', 'Ad Spend Additional', 'Keyword Research', 'Campaign Monitoring'];
        $service->repair($campaign, $service->snapshot, $this->limited());
        $service->assetMutationQuota = true;
        $service->writes = [];

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('creative_repair_or_readback_failed', $result['metadata']['reason']);
        $this->assertNull($result['metadata']['next_check_at']);
        $attempt = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail();
        $this->assertSame('needs_review', $attempt->status);
        $this->assertCount(3, $attempt->details['assets']);
        $this->assertArrayNotHasKey('asset_resource', $attempt->details['assets'][2]);
        $service->writes = [];
        $service->assetMutationQuota = false;

        $waiting = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('needs_review', $waiting['status']);
        $this->assertSame([], $service->writes);
        $this->assertSame(1, $service->generationCalls);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
    }

    public function test_incomplete_reviewed_asset_list_cannot_enter_activation_even_with_an_approved_rsa(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->oldCallout();
        $service->repair($campaign, $service->snapshot, $this->limited());
        $attempt = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail();
        $attempt->update(['details' => array_replace($attempt->details, ['assets' => []])]);
        $service->approve();
        $service->writes = [];

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('needs_review', $attempt->fresh()->status);
        $this->assertSame([], $service->writes);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
        $this->assertSame('ENABLED', $service->assetRows[RankRepairFixture::OLD_ASSET]['campaignAsset']['status']);
    }

    public function test_validation_is_bounded_to_four_sitelinks_four_callouts_and_one_snippet(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->proposal['callouts'] = array_map(fn ($i) => 'Reviewed Benefit '.$i, range(1, 6));
        $service->proposal['sitelinks'] = array_map(fn ($i) => ['link_text' => 'Reviewed Link '.$i,
            'url' => 'https://example.com', 'description1' => 'Google Ads management.', 'description2' => 'Review before launch.'], range(1, 6));
        $service->proposal['structured_snippet'] = ['header' => 'Services', 'values' => ['Keyword Research', 'Ad Copy Preparation', 'Campaign Monitoring']];

        $result = $service->dryRun()->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('no_op', $result['status']);
        $this->assertCount(10, $service->writes, 'One RSA plus at most nine assets are validated.');
        $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->count());
    }

    public function test_every_sdk_mutation_uses_a_bounded_timeout_and_no_automatic_retries(): void
    {
        [$campaign] = $this->fixture();
        $service = (new GoogleAdRankRepair($this->createMock(GeminiService::class), new AdvertisingEvidence))->dryRun();
        $options = ['retrySettings' => ['retriesEnabled' => false, 'noRetriesRpcTimeoutMillis' => 30_000]];
        $adClient = $this->createMock(AdGroupAdServiceClient::class);
        $adClient->expects($this->exactly(2))->method('mutateAdGroupAds')->willReturnCallback(function ($request, $callOptions) use ($options) {
            $this->assertSame($options, $callOptions);
            $this->assertTrue($request->getValidateOnly());

            return new MutateAdGroupAdsResponse(['results' => [new MutateAdGroupAdResult(['resource_name' => RankRepairFixture::NEW])]]);
        });
        $assetClient = $this->createMock(AssetServiceClient::class);
        $assetClient->expects($this->once())->method('mutateAssets')->willReturnCallback(function ($request, $callOptions) use ($options) {
            $this->assertSame($options, $callOptions);
            $this->assertTrue($request->getValidateOnly());

            return new MutateAssetsResponse(['results' => [new MutateAssetResult(['resource_name' => RankRepairFixture::NEW_ASSET])]]);
        });
        $linkClient = $this->createMock(CampaignAssetServiceClient::class);
        $linkClient->expects($this->exactly(2))->method('mutateCampaignAssets')->willReturnCallback(function ($request, $callOptions) use ($options) {
            $this->assertSame($options, $callOptions);
            $this->assertTrue($request->getValidateOnly());

            return new MutateCampaignAssetsResponse(['results' => [new MutateCampaignAssetResult(['resource_name' => RankRepairFixture::NEW_LINK])]]);
        });
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getAdGroupAdServiceClient')->willReturn($adClient);
        $client->method('getAssetServiceClient')->willReturn($assetClient);
        $client->method('getCampaignAssetServiceClient')->willReturn($linkClient);
        $account = $this->createMock(AccountStructureService::class);
        $account->method('getClient')->willReturn($client);
        $this->app->bind(AccountStructureService::class, fn () => $account);
        $copy = ['headline_assets' => [['text' => 'Google Ads Management']], 'description_assets' => [['text' => 'Review ads before launch.']],
            'path1' => '', 'path2' => '', 'final_urls' => ['https://example.com?utm_campaign=61']];

        (new \ReflectionMethod($service, 'createPausedAd'))->invoke($service, $campaign, 'customers/1234567890/adGroups/7', $copy);
        (new \ReflectionMethod($service, 'setAdStatus'))->invoke($service, $campaign, RankRepairFixture::NEW, 'ENABLED');
        (new \ReflectionMethod($service, 'createAsset'))->invoke($service, $campaign, ['field_type' => 'CALLOUT', 'text' => 'Review Before Launch']);
        (new \ReflectionMethod($service, 'linkPausedAsset'))->invoke($service, $campaign, RankRepairFixture::NEW_ASSET, 'CALLOUT');
        (new \ReflectionMethod($service, 'setAssetStatus'))->invoke($service, $campaign, RankRepairFixture::NEW_LINK, 'ENABLED');
        Http::assertNothingSent();
    }

    public function test_pending_staged_repair_blocks_legacy_in_place_strength_writer_even_outside_trial(): void
    {
        [$campaign, $strategy, $service] = $this->fixture(false);
        $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('ad_rank_replacement_pending', app(GoogleAdStrengthRepair::class)->mutationBlocked($campaign, $strategy));
        $this->assertNull(app(GoogleAdStrengthRepair::class)->mutationBlocked($campaign, $strategy, true));
    }

    public function test_failed_generation_or_review_has_a_durable_attempt_and_is_not_retried_hourly(): void
    {
        foreach (['generation', 'review'] as $failure) {
            [$campaign, , $service] = $this->fixture();
            $service->generationFails = $failure === 'generation';
            $service->reviewApproved = $failure !== 'review';
            $first = $service->repair($campaign, $service->snapshot, $this->limited());
            $second = $service->repair($campaign, $service->snapshot, $this->limited());

            $this->assertContains($first['status'], ['unavailable', 'needs_review']);
            $this->assertSame('needs_review', $second['status']);
            $this->assertSame(1, $service->generationCalls);
            $this->assertSame([], $service->writes);
            $this->assertSame('needs_review', AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail()->status);
        }
    }

    public function test_scoped_callout_replacement_waits_for_review_then_verifies_new_before_pausing_old(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->oldCallout();
        $result = $service->repair($campaign, $service->snapshot, $this->limited());
        $this->assertSame('verifying', $result['status']);
        $this->assertSame('ENABLED', $service->assetRows[RankRepairFixture::OLD_ASSET]['campaignAsset']['status']);
        $this->assertSame('PAUSED', $service->assetRows[RankRepairFixture::NEW_ASSET]['campaignAsset']['status']);
        $service->approve();
        $service->writes = [];

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('observing_delivery', $result['metadata']['phase']);
        $this->assertSame('ENABLED', $service->assetRows[RankRepairFixture::NEW_ASSET]['campaignAsset']['status']);
        $this->assertSame('PAUSED', $service->assetRows[RankRepairFixture::OLD_ASSET]['campaignAsset']['status']);
        $this->assertSame([['asset_status', RankRepairFixture::NEW_LINK, 'ENABLED'], ['asset_status', RankRepairFixture::OLD_LINK, 'PAUSED'],
            ['status', RankRepairFixture::NEW, 'ENABLED'], ['status', RankRepairFixture::OLD, 'PAUSED']], $service->writes);
        $this->assertFalse($result['metadata']['rank_recovery_verified']);
    }

    public function test_foreign_asset_replacement_plan_never_creates_or_pauses_ads_or_assets(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->oldCallout();
        $service->proposal['existing_campaign_assets'][0]['link_resource_name'] = 'customers/9999999999/campaignAssets/61~90~CALLOUT';

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame([], $service->writes);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
    }

    public function test_a_failed_asset_activation_keeps_the_working_ad_and_persists_actual_partial_statuses(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->oldCallout();
        $service->repair($campaign, $service->snapshot, $this->limited());
        $service->approve();
        $service->assetActivationFails = true;

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertContains($result['status'], ['needs_review', 'unavailable']);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
        $this->assertSame('PAUSED', $service->rows[RankRepairFixture::NEW]['adGroupAd']['status']);
        $attempt = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail();
        $this->assertSame('needs_review', $attempt->status);
        $this->assertSame('PAUSED', $attempt->details['compensation_readback']['assets'][0]['new_status']);
        $this->assertSame('ENABLED', $attempt->details['compensation_readback']['assets'][0]['old_status']);
    }

    private function activated(): array
    {
        [$campaign, $strategy, $service] = $this->fixture();
        $service->repair($campaign, $service->snapshot, $this->limited());
        $service->approve();
        $result = $service->repair($campaign, $service->snapshot, $this->limited());
        $this->assertSame('observing_delivery', $result['metadata']['phase']);

        return [$campaign, $strategy, $service, $result['metadata']['activated_at']];
    }

    private function postActivationEvidence(RankRepairFixture $service, string $activatedAt): void
    {
        $from = CarbonImmutable::parse($activatedAt)->setTimezone('Australia/Sydney')->addDay()->startOfDay();
        $through = $from->addDay();
        $service->snapshot['measurement'] = ['started_at' => $activatedAt, 'from' => $from->toIso8601String(),
            'through' => $through->addDay()->toIso8601String(), 'complete_hours' => 48];
        $service->snapshot['rank_evidence'] = ['status' => 'verified', 'source' => 'google_search_share_metrics',
            'checked_at' => now()->toIso8601String(), 'account_timezone' => 'Australia/Sydney',
            'from' => $from->toDateString(), 'through' => $through->toDateString(), 'complete_days' => 2,
            'impressions' => 30, 'clicks' => 5, 'cost_micros' => 3_000_000,
            'lost_to_rank' => 0.3, 'lost_to_budget' => 0.0, 'impression_share' => 0.7];
    }

    public function test_only_fresh_complete_post_replacement_rank_and_click_evidence_confirms_recovery(): void
    {
        [$campaign, , $service, $activatedAt] = $this->activated();
        $this->travel(3)->days();
        $this->postActivationEvidence($service, $activatedAt);
        $service->writes = [];

        $result = $service->repair($campaign, $service->snapshot, ['status' => 'not_rank_limited']);

        $this->assertSame('no_op', $result['status']);
        $this->assertTrue($result['metadata']['rank_recovery_verified']);
        $this->assertSame('delivery_verified', $result['metadata']['phase']);
        $this->assertSame(0, $result['metadata']['actions_taken']);
        $this->assertSame([], $service->writes);
        $this->assertSame('PENDING', $service->rows[RankRepairFixture::NEW]['adGroupAd']['adStrength'], 'Actual rank and clicks, not the strength rating, verify recovery.');
        $this->assertSame('completed', AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail()->status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidObservationEvidence')]
    public function test_unknown_stale_partial_or_pre_replacement_rank_evidence_cannot_confirm_recovery(string $invalid): void
    {
        [$campaign, , $service, $activatedAt] = $this->activated();
        $this->travel(3)->days();
        $this->postActivationEvidence($service, $activatedAt);
        if ($invalid === 'unknown') {
            $service->snapshot['rank_evidence']['status'] = 'unavailable';
        } elseif ($invalid === 'stale') {
            $service->snapshot['rank_evidence']['checked_at'] = now()->subHours(5)->toIso8601String();
        } elseif ($invalid === 'partial') {
            $service->snapshot['measurement']['complete_hours'] = 12;
            $service->snapshot['rank_evidence']['complete_days'] = 0;
        } else {
            $from = CarbonImmutable::parse($activatedAt)->setTimezone('Australia/Sydney')->subDays(2)->startOfDay();
            $service->snapshot['rank_evidence']['from'] = $from->toDateString();
            $service->snapshot['rank_evidence']['through'] = $from->addDay()->toDateString();
            $service->snapshot['measurement']['from'] = $from->toIso8601String();
            $service->snapshot['measurement']['through'] = $from->addDays(2)->toIso8601String();
        }
        $service->writes = [];

        $result = $service->repair($campaign, $service->snapshot, ['status' => 'not_rank_limited']);

        $this->assertFalse($result['metadata']['rank_recovery_verified']);
        $this->assertNotSame('delivery_verified', $result['metadata']['phase']);
        $this->assertSame([], $service->writes);
        $this->assertNotSame('completed', AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail()->status);
    }

    public static function invalidObservationEvidence(): array
    {
        return [['unknown'], ['stale'], ['partial'], ['pre_replacement']];
    }

    public function test_unverified_delivery_after_72_hours_needs_review_without_restarting_the_trial(): void
    {
        [$campaign, , $service] = $this->activated();
        $before = $campaign->fresh()->spend_guardrails;
        $this->travel(4)->days();
        $service->writes = [];

        $result = $service->repair($campaign, $service->snapshot, ['status' => 'unavailable']);

        $this->assertSame('needs_review', $result['status']);
        $this->assertFalse($result['metadata']['rank_recovery_verified']);
        $this->assertSame('rank_improvement_not_verified', $result['metadata']['reason']);
        $this->assertSame([], $service->writes);
        $this->assertSame($before, $campaign->fresh()->spend_guardrails);
        $this->assertSame(1, $service->generationCalls);
    }

    public function test_paused_staging_keeps_one_full_step_guard_and_batches_prefix_reads_without_skipping_write_readbacks(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->proposal['callouts'] = ['Review Before Launch', 'Ad Spend Additional', 'Keyword Research', 'Campaign Monitoring'];
        $service->repair($campaign, $service->snapshot, $this->limited());
        $before = $service->readCounts;
        $compact = $service->compactReads;
        $service->writes = [];
        $result = $service->repair($campaign, $service->snapshot, ['status' => 'collecting_evidence']);

        $this->assertSame('awaiting_policy_review', $result['metadata']['phase']);
        $this->assertSame(1, $service->readCounts['inspect'] - $before['inspect']);
        $this->assertSame(4, $service->compactReads - $compact, 'Both create and PAUSED link writes keep their own fresh critical guard.');
        $this->assertSame(3, $service->readCounts['assets'] - $before['assets'], 'One prefix read plus two fresh post-link readbacks.');
        $this->assertNotContains(['status', RankRepairFixture::NEW, 'ENABLED'], $service->writes);
        foreach ($service->assetRows as $row) {
            $this->assertSame('PAUSED', $row['campaignAsset']['status']);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCompactStagingEvidence')]
    public function test_incomplete_or_unsafe_compact_evidence_stops_the_first_paused_write(array $override): void
    {
        [$campaign, , $service] = $this->fixture();
        $before = $campaign->fresh()->spend_guardrails;
        $service->stagingOverrides = $override;
        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('held', $result['status']);
        $this->assertSame([], $service->writes);
        $this->assertSame($before, $campaign->fresh()->spend_guardrails);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
    }

    public static function invalidCompactStagingEvidence(): array
    {
        return [[['cost_micros' => null]], [['cost_micros' => 70_000_000]], [['modifier_reads_complete' => false]],
            [['amplifying_bid_modifiers' => [['resource' => 'customers/1234567890/campaignCriteria/61~1', 'multiplier' => 1.5]]]],
            [['daily_budget_micros' => 11_000_000]], [['actual_cpc_ceiling_micros' => 4_000_000]],
            [['search_partners_enabled' => true]], [['currency_code' => 'USD']], [['time_zone' => 'UTC']],
            [['budget_resource' => 'customers/9999999999/campaignBudgets/90']], [['campaign_resource' => 'customers/1234567890/campaigns/62']]];
    }

    public function test_owner_pause_during_compact_reads_blocks_the_paused_creation_without_resetting_the_trial(): void
    {
        [$campaign, , $service] = $this->fixture();
        $before = $campaign->fresh()->spend_guardrails;
        $service->onStagingSafety = function (Campaign $observed): void {
            $observed->fresh()->update(['status' => 'paused']);
        };
        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('held', $result['status']);
        $this->assertSame('campaign_not_active', $result['metadata']['reason']);
        $this->assertSame([], $service->writes);
        $this->assertSame($before, $campaign->fresh()->spend_guardrails);
    }

    public function test_current_original_copy_is_still_checked_after_compact_financial_reads(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->onStagingSafety = function () use ($service): void {
            $service->rows[RankRepairFixture::OLD]['adGroupAd']['ad']['responsiveSearchAd']['headlines'][0]['text'] = 'Owner changed the ad';
        };
        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('held', $result['status']);
        $this->assertSame('working_ad_changed_or_paused', $result['metadata']['reason']);
        $this->assertSame([], $service->writes);
    }

    public function test_full_activation_guard_still_blocks_a_changed_keyword_configuration_after_compact_staging(): void
    {
        [$campaign, , $service] = $this->fixture();
        $service->repair($campaign, $service->snapshot, $this->limited());
        $service->approve();
        $service->snapshot['keywords'][0]['text'] = 'Different owner targeting';
        $service->writes = [];
        $result = $service->repair($campaign, $service->snapshot, ['status' => 'collecting_evidence']);

        $this->assertSame('held', $result['status']);
        $this->assertSame('serving_settings_changed_during_creative_repair', $result['metadata']['reason']);
        $this->assertSame([], $service->writes);
        $this->assertSame('PAUSED', $service->rows[RankRepairFixture::NEW]['adGroupAd']['status']);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
    }

    private function stagedFourPairs(): array
    {
        [$campaign, $strategy, $service] = $this->fixture();
        $service->oldCallouts();
        $service->repair($campaign, $service->snapshot, $this->limited());
        $staged = $service->repair($campaign, $service->snapshot, $this->limited());
        $this->assertSame('awaiting_policy_review', $staged['metadata']['phase']);
        $service->approve();
        $service->writes = [];
        $service->servingGuards = [];
        $service->activationWriteDetails = [];

        return [$campaign, $strategy, $service];
    }

    public function test_activation_completes_only_two_verified_pairs_per_check_and_keeps_the_original_rsa_until_the_full_plan(): void
    {
        [$campaign, $strategy, $service] = $this->stagedFourPairs();
        $before = $strategy->fresh()->execution_result;
        $fullReads = $service->readCounts['inspect'];
        $compactReads = $service->compactReads;

        $first = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('verifying', $first['status']);
        $this->assertSame('activating', $first['metadata']['phase']);
        $this->assertSame(2, $first['metadata']['activated_asset_count']);
        $this->assertSame(4, $first['metadata']['actions_taken']);
        $this->assertCount(4, $service->writes);
        $this->assertSame($compactReads, $service->compactReads, 'Serving writes never use compact financial guards.');
        $this->assertSame(5, $service->readCounts['inspect'] - $fullReads, 'One step guard and one full guard per status mutation.');
        $this->assertSame(range($fullReads + 2, $fullReads + 5), $service->servingGuards);
        $intent = $service->activationWriteDetails[0];
        $this->assertSame(0, $intent['activated_asset_count']);
        $this->assertSame('asset_pair', $intent['activation_inflight']['kind']);
        $this->assertSame(0, $intent['activation_inflight']['index']);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
        $this->assertSame('PAUSED', $service->rows[RankRepairFixture::NEW]['adGroupAd']['status']);
        $this->assertSame($before, $strategy->fresh()->execution_result, 'No full replacement baseline is saved for a partial activation.');
        $attempt = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail();
        $this->assertNull($attempt->details['activation_inflight']);
        $firstPairs = array_slice($attempt->details['assets'], 0, 2);
        $service->writes = [];
        $service->servingGuards = [];
        $fullReads = $service->readCounts['inspect'];

        $second = $service->repair($campaign, $service->snapshot, ['status' => 'collecting_evidence']);

        $this->assertSame('observing_delivery', $second['metadata']['phase']);
        $this->assertSame(4, $second['metadata']['activated_asset_count']);
        $this->assertSame(6, $second['metadata']['actions_taken']);
        $this->assertSame(7, $service->readCounts['inspect'] - $fullReads);
        $this->assertSame(range($fullReads + 2, $fullReads + 7), $service->servingGuards);
        foreach ($firstPairs as $plan) {
            $this->assertNotContains(['asset_status', $plan['link_resource'], 'ENABLED'], $service->writes);
            $this->assertNotContains(['asset_status', $plan['old_link_resource'], 'PAUSED'], $service->writes);
        }
        $this->assertSame('PAUSED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::NEW]['adGroupAd']['status']);
        $this->assertSame(1, $service->generationCalls);
        $this->assertFalse($second['metadata']['rank_recovery_verified']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeActivationPrefixes')]
    public function test_changed_completed_prefix_or_untouched_remainder_requires_review_without_replaying_status_writes(string $change): void
    {
        [$campaign, , $service] = $this->stagedFourPairs();
        $service->repair($campaign, $service->snapshot, $this->limited());
        $attempt = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail();
        $plan = $attempt->details['assets'][str_starts_with($change, 'completed') ? 0 : 2];
        if ($change === 'completed_content') {
            $service->assetRows[$plan['asset_resource']]['asset']['calloutAsset']['calloutText'] = 'Owner edited this asset';
        } elseif ($change === 'completed_old_status') {
            $service->assetRows[$plan['old_asset_resource']]['campaignAsset']['status'] = 'ENABLED';
        } elseif ($change === 'remainder_new_status') {
            $service->assetRows[$plan['asset_resource']]['campaignAsset']['status'] = 'ENABLED';
        } else {
            $service->assetRows[$plan['old_asset_resource']]['campaignAsset']['status'] = 'PAUSED';
        }
        $service->writes = [];

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('needs_review', $attempt->fresh()->status);
        $this->assertSame([], $service->writes);
        $this->assertSame(2, $result['metadata']['activated_asset_count']);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
        $this->assertSame('PAUSED', $service->rows[RankRepairFixture::NEW]['adGroupAd']['status']);
    }

    public static function unsafeActivationPrefixes(): array
    {
        return [['completed_content'], ['completed_old_status'], ['remainder_new_status'], ['remainder_old_status']];
    }

    public function test_owner_pause_between_activation_batches_holds_the_remaining_writes_without_restarting_anything(): void
    {
        [$campaign, , $service] = $this->stagedFourPairs();
        $service->repair($campaign, $service->snapshot, $this->limited());
        $before = $campaign->fresh()->spend_guardrails;
        $campaign->update(['status' => 'paused']);
        $service->writes = [];

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('held', $result['status']);
        $this->assertSame('campaign_not_active', $result['metadata']['reason']);
        $this->assertSame(2, $result['metadata']['activated_asset_count']);
        $this->assertSame([], $service->writes);
        $this->assertSame($before, $campaign->fresh()->spend_guardrails);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
    }

    public function test_an_inflight_activation_without_a_durable_readback_receipt_cannot_be_replayed(): void
    {
        [$campaign, , $service] = $this->stagedFourPairs();
        $service->repair($campaign, $service->snapshot, $this->limited());
        $attempt = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail();
        $attempt->update(['details' => array_replace($attempt->details, ['activation_inflight' => ['kind' => 'asset_pair', 'index' => 2]])]);
        $service->writes = [];

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('needs_review', $attempt->fresh()->status);
        $this->assertSame([], $service->writes);
        $this->assertSame(2, $result['metadata']['activated_asset_count']);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
    }

    public function test_a_read_quota_before_the_first_activation_rpc_does_not_create_an_uncertain_write_intent(): void
    {
        [$campaign, , $service] = $this->stagedFourPairs();
        $firstMutationGuard = $service->readCounts['inspect'] + 2;
        $service->onInspection = function (Campaign $observed, int $count) use ($firstMutationGuard): void {
            if ($count === $firstMutationGuard) {
                throw new ApiException('Too many requests. Retry in 900 seconds.', 8, 'RESOURCE_EXHAUSTED');
            }
        };

        $result = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('google_api_quota_cooldown', $result['metadata']['reason']);
        $this->assertSame(0, $result['metadata']['actions_taken']);
        $attempt = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail();
        $this->assertNull($attempt->details['activation_inflight'] ?? null);
        $this->assertSame(0, $attempt->details['activated_asset_count']);
        $this->assertSame([], $service->writes);
        $service->onInspection = null;
        $this->travel(901)->seconds();

        $resumed = $service->repair($campaign, $service->snapshot, $this->limited());

        $this->assertSame('activating', $resumed['metadata']['phase']);
        $this->assertSame(2, $resumed['metadata']['activated_asset_count']);
        $this->assertSame('ENABLED', $service->rows[RankRepairFixture::OLD]['adGroupAd']['status']);
    }
}

class RankRepairFixture extends GoogleAdRankRepair
{
    public const OLD = 'customers/1234567890/adGroupAds/7~10';

    public const NEW = 'customers/1234567890/adGroupAds/7~11';

    public const OLD_ASSET = 'customers/1234567890/assets/90';

    public const NEW_ASSET = 'customers/1234567890/assets/91';

    public const OLD_LINK = 'customers/1234567890/campaignAssets/61~90~CALLOUT';

    public const NEW_LINK = 'customers/1234567890/campaignAssets/61~91~CALLOUT';

    public array $snapshot;

    public array $spendData;

    public array $rows;

    public array $writes = [];

    public array $assetRows = [];

    public array $detailsBeforeFirstWrite = [];

    public array $detailsBeforeAssetCreate = [];

    public ?string $quotaReadSource = null;

    public int $readCalls = 0;

    public array $readCounts = [];

    public array $servingGuards = [];

    public array $activationWriteDetails = [];

    public ?\Closure $onInspection = null;

    public int $compactReads = 0;

    public array $stagingOverrides = [];

    public ?\Closure $onStagingSafety = null;

    public bool $assetMutationQuota = false;

    private int $createdAssets = 0;

    public bool $assetActivationFails = false;

    public bool $generationFails = false;

    public bool $reviewApproved = true;

    public int $generationCalls = 0;

    public array $proposal = ['headlines' => ['Google Ads Management', 'Review Before Launch', 'Keyword Research Included'],
        'descriptions' => ['Review your ads before launch.', 'Google Ads management for your business.']];

    public bool $hideOriginalAfterPause = false;

    private int $hiddenReads = 0;

    public function __construct(GeminiService $gemini, AdvertisingEvidence $evidence)
    {
        parent::__construct($gemini, $evidence);
        $this->snapshot = ['status' => 2, 'channel' => 2, 'daily_budget_micros' => 10_000_000,
            'start_date' => now('Australia/Sydney')->subDays(3)->toDateString(),
            'start_date_time' => now('Australia/Sydney')->subDays(3)->toDateString().' 00:00:00',
            'end_date_time' => now('Australia/Sydney')->addMonth()->toDateString().' 23:59:59',
            'end_date' => now('Australia/Sydney')->addMonth()->toDateString(), 'portfolio_bidding_strategy' => '',
            'google_search_enabled' => true, 'search_partners_enabled' => false, 'display_enabled' => false,
            'positive_geo_target_type' => 7,
            'actual_cpc_ceiling_micros' => 3_000_000, 'cpc_bid_ceiling_micros' => 3_000_000,
            'bidding_strategy' => BiddingStrategyType::TARGET_SPEND, 'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney',
            'keywords' => [['text' => 'google ads management', 'status' => 'ENABLED']],
            'measurement' => ['complete_hours' => 48, 'started_at' => now('Australia/Sydney')->startOfDay()->subDays(2)->toIso8601String(),
                'from' => now('Australia/Sydney')->startOfDay()->subDays(2)->toIso8601String(),
                'through' => now('Australia/Sydney')->startOfDay()->toIso8601String()],
            'rank_evidence' => ['status' => 'verified', 'checked_at' => now()->toIso8601String(), 'source' => 'google_search_share_metrics',
                'account_timezone' => 'Australia/Sydney', 'from' => now('Australia/Sydney')->startOfDay()->subDays(2)->toDateString(),
                'through' => now('Australia/Sydney')->startOfDay()->subDay()->toDateString(),
                'complete_days' => 2, 'impressions' => 20, 'clicks' => 0, 'cost_micros' => 0,
                'lost_to_rank' => 0.9, 'lost_to_budget' => 0.0, 'impression_share' => 0.1]];
        $this->spendData = ['cost_micros' => 20_000_000, 'daily_budget_micros' => 10_000_000, 'cpc_bid_ceiling_micros' => 3_000_000,
            'currency_code' => 'AUD', 'account_timezone' => 'Australia/Sydney', 'status' => 2, 'bidding_strategy' => BiddingStrategyType::TARGET_SPEND,
            'amplifying_bid_modifiers' => []];
        $this->rows = [self::OLD => ['campaign' => ['status' => 'ENABLED'], 'adGroup' => ['status' => 'ENABLED'],
            'adGroupAd' => ['resourceName' => self::OLD, 'status' => 'ENABLED', 'adStrength' => 'AVERAGE',
                'policySummary' => ['approvalStatus' => 'APPROVED', 'reviewStatus' => 'REVIEWED'],
                'ad' => ['finalUrls' => ['https://example.com?utm_campaign=61'], 'responsiveSearchAd' => [
                    'headlines' => [['text' => 'Old approved headline']], 'descriptions' => [['text' => 'Old description']], 'path1' => '', 'path2' => '',
                ]]]]];
    }

    public function approve(): void
    {
        $this->rows[self::NEW]['adGroupAd']['policySummary'] = ['approvalStatus' => 'APPROVED', 'reviewStatus' => 'REVIEWED'];
        foreach ($this->assetRows as &$asset) {
            $asset['asset']['policySummary'] = ['approvalStatus' => 'APPROVED', 'reviewStatus' => 'REVIEWED'];
        }
    }

    public function oldCallout(): void
    {
        $this->assetRows[self::OLD_ASSET] = ['campaignAsset' => ['resourceName' => self::OLD_LINK, 'asset' => self::OLD_ASSET,
            'fieldType' => 'CALLOUT', 'status' => 'ENABLED'], 'asset' => ['resourceName' => self::OLD_ASSET,
                'calloutAsset' => ['calloutText' => 'Starts at $149/month'], 'policySummary' => ['approvalStatus' => 'APPROVED', 'reviewStatus' => 'REVIEWED']]];
        $this->proposal['callouts'] = ['US$149/Month Plans'];
        $this->proposal['existing_campaign_assets'] = [['field_type' => 'CALLOUT', 'asset_resource_name' => self::OLD_ASSET, 'link_resource_name' => self::OLD_LINK]];
    }

    public function oldCallouts(): void
    {
        $this->proposal['callouts'] = ['Review Before Launch', 'Ad Spend Additional', 'Keyword Research', 'Campaign Monitoring'];
        $this->proposal['existing_campaign_assets'] = [];
        foreach (range(200, 203) as $id) {
            $asset = 'customers/1234567890/assets/'.$id;
            $link = 'customers/1234567890/campaignAssets/61~'.$id.'~CALLOUT';
            $this->assetRows[$asset] = ['campaignAsset' => ['resourceName' => $link, 'asset' => $asset, 'fieldType' => 'CALLOUT', 'status' => 'ENABLED'],
                'asset' => ['resourceName' => $asset, 'calloutAsset' => ['calloutText' => 'Previous Callout '.$id],
                    'policySummary' => ['approvalStatus' => 'APPROVED', 'reviewStatus' => 'REVIEWED']]];
            $this->proposal['existing_campaign_assets'][] = ['field_type' => 'CALLOUT', 'asset_resource_name' => $asset, 'link_resource_name' => $link];
        }
    }

    protected function inspect(Campaign $campaign): ?array
    {
        $this->read('inspect');
        if ($this->onInspection) {
            ($this->onInspection)($campaign, $this->readCounts['inspect']);
        }

        return $this->snapshot;
    }

    protected function spend(Campaign $campaign): array
    {
        $this->read('spend');

        return $this->spendData;
    }

    protected function stagingSafety(Campaign $campaign): array
    {
        $this->read('spend');
        $this->compactReads++;
        if ($this->onStagingSafety) {
            ($this->onStagingSafety)($campaign, $this->compactReads);
        }

        return $this->stagingOverrides + $this->snapshot + ['campaign_resource' => $campaign->googleAdsResourceName(),
            'budget_resource' => 'customers/1234567890/campaignBudgets/90', 'cost_micros' => $this->spendData['cost_micros'],
            'amplifying_bid_modifiers' => $this->spendData['amplifying_bid_modifiers'],
            'modifier_reads_complete' => true, 'checked_at' => now()->toIso8601String()];
    }

    protected function assets(Campaign $campaign): array
    {
        $this->read('assets');

        return $this->assetRows;
    }

    protected function ads(Campaign $campaign): array
    {
        $this->read('ads');
        if ($this->hiddenReads > 0) {
            $this->hiddenReads--;

            return [self::NEW => $this->rows[self::NEW]];
        }

        return $this->rows;
    }

    private function read(string $source): void
    {
        $this->readCalls++;
        $this->readCounts[$source] = ($this->readCounts[$source] ?? 0) + 1;
        if ($this->quotaReadSource === $source) {
            throw new ApiException('Too many requests. Retry in 900 seconds.', 8, 'RESOURCE_EXHAUSTED');
        }
    }

    protected function generateCopy(Campaign $campaign, Strategy $strategy, array $original, array $snapshot, bool $ownerRequested): array
    {
        $this->generationCalls++;
        if ($this->generationFails) {
            throw new \RuntimeException('Creative provider unavailable.');
        }

        return $this->proposal;
    }

    protected function reviewCopy(Strategy $strategy, array $copy): array
    {
        return ['overall_status' => $this->reviewApproved ? 'approved' : 'needs_revision'];
    }

    protected function reviewAssets(Campaign $campaign, Strategy $strategy, array $plans): bool
    {
        return true;
    }

    protected function createPausedAd(Campaign $campaign, string $group, array $copy): ?string
    {
        $this->writes[] = [$this->isDryRun() ? 'validate_ad' : 'create_paused_ad'];
        if ($this->isDryRun()) {
            return null;
        }
        $this->detailsBeforeFirstWrite = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail()->details;
        $this->rows[self::NEW] = ['campaign' => ['status' => 'ENABLED'], 'adGroup' => ['status' => 'ENABLED'],
            'adGroupAd' => ['resourceName' => self::NEW, 'status' => 'PAUSED', 'adStrength' => 'PENDING',
                'policySummary' => ['approvalStatus' => 'UNKNOWN', 'reviewStatus' => 'REVIEW_IN_PROGRESS'],
                'ad' => ['finalUrls' => $copy['final_urls'], 'responsiveSearchAd' => ['headlines' => $copy['headline_assets'],
                    'descriptions' => $copy['description_assets'], 'path1' => $copy['path1'], 'path2' => $copy['path2']]]]];

        return self::NEW;
    }

    protected function setAdStatus(Campaign $campaign, string $resource, string $status): bool
    {
        $this->servingGuards[] = $this->readCounts['inspect'] ?? 0;
        $this->writes[] = ['status', $resource, $status];
        $this->rows[$resource]['adGroupAd']['status'] = $status;
        if ($resource === self::OLD && $status === 'PAUSED' && $this->hideOriginalAfterPause) {
            $this->hiddenReads = 1;
        }

        return true;
    }

    protected function createAsset(Campaign $campaign, array $plan): ?string
    {
        $this->writes[] = ['create_asset'];
        if ($this->isDryRun()) {
            return null;
        }
        if ($this->assetMutationQuota) {
            throw new ApiException('Too many requests. Retry in 900 seconds.', 8, 'RESOURCE_EXHAUSTED');
        }
        if ($this->detailsBeforeAssetCreate === []) {
            $this->detailsBeforeAssetCreate = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail()->details;
        }
        $resource = 'customers/1234567890/assets/'.(91 + $this->createdAssets++);
        $this->assetRows[$resource] = ['asset' => ['resourceName' => $resource,
            'calloutAsset' => ['calloutText' => $plan['text']], 'policySummary' => ['approvalStatus' => 'UNKNOWN', 'reviewStatus' => 'REVIEW_IN_PROGRESS']]];

        return $resource;
    }

    protected function linkPausedAsset(Campaign $campaign, string $asset, string $field): ?string
    {
        $this->writes[] = ['link_asset_paused'];
        $resource = 'customers/1234567890/campaignAssets/61~'.basename($asset).'~'.$field;
        $this->assetRows[$asset]['campaignAsset'] = ['resourceName' => $resource, 'asset' => $asset, 'fieldType' => $field, 'status' => 'PAUSED'];

        return $resource;
    }

    protected function setAssetStatus(Campaign $campaign, string $resource, string $status): bool
    {
        $this->servingGuards[] = $this->readCounts['inspect'] ?? 0;
        $this->activationWriteDetails[] = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_rank_repair')->firstOrFail()->details;
        $this->writes[] = ['asset_status', $resource, $status];
        if ($this->assetActivationFails && $status === 'ENABLED') {
            return false;
        }
        foreach ($this->assetRows as &$asset) {
            if (($asset['campaignAsset']['resourceName'] ?? null) === $resource) {
                $asset['campaignAsset']['status'] = $status;

                return true;
            }
        }

        return false;
    }
}
