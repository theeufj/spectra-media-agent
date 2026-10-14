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
use App\Services\GoogleAds\GoogleAdRankRepair;
use App\Services\GoogleAds\GoogleAdStrengthRepair;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
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
                'max_daily_budget_micros' => 10_000_000, 'max_cpc_bid_micros' => 3_000_000] : null]);
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

    protected function inspect(Campaign $campaign): ?array
    {
        return $this->snapshot;
    }

    protected function spend(Campaign $campaign): array
    {
        return $this->spendData;
    }

    protected function assets(Campaign $campaign): array
    {
        return $this->assetRows;
    }

    protected function ads(Campaign $campaign): array
    {
        if ($this->hiddenReads > 0) {
            $this->hiddenReads--;

            return [self::NEW => $this->rows[self::NEW]];
        }

        return $this->rows;
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
        $this->rows[self::NEW] = ['campaign' => ['status' => 'ENABLED'], 'adGroup' => ['status' => 'ENABLED'],
            'adGroupAd' => ['resourceName' => self::NEW, 'status' => 'PAUSED', 'adStrength' => 'PENDING',
                'policySummary' => ['approvalStatus' => 'UNKNOWN', 'reviewStatus' => 'REVIEW_IN_PROGRESS'],
                'ad' => ['finalUrls' => $copy['final_urls'], 'responsiveSearchAd' => ['headlines' => $copy['headline_assets'],
                    'descriptions' => $copy['description_assets'], 'path1' => $copy['path1'], 'path2' => $copy['path2']]]]];

        return self::NEW;
    }

    protected function setAdStatus(Campaign $campaign, string $resource, string $status): bool
    {
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
        $this->assetRows[self::NEW_ASSET] = ['asset' => ['resourceName' => self::NEW_ASSET,
            'calloutAsset' => ['calloutText' => $plan['text']], 'policySummary' => ['approvalStatus' => 'UNKNOWN', 'reviewStatus' => 'REVIEW_IN_PROGRESS']]];

        return self::NEW_ASSET;
    }

    protected function linkPausedAsset(Campaign $campaign, string $asset, string $field): ?string
    {
        $this->writes[] = ['link_asset_paused'];
        $this->assetRows[$asset]['campaignAsset'] = ['resourceName' => self::NEW_LINK, 'asset' => $asset, 'fieldType' => $field, 'status' => 'PAUSED'];

        return self::NEW_LINK;
    }

    protected function setAssetStatus(Campaign $campaign, string $resource, string $status): bool
    {
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
