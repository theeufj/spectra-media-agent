<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\AgentIssue;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\AdExtensionBuilder;
use App\Services\Agents\Google\AudienceTargeter;
use App\Services\Agents\Google\BiddingStrategyApplier;
use App\Services\Agents\Google\Executors\SearchCampaignExecutor;
use App\Services\Agents\Google\GeoTargetResolver;
use App\Services\Agents\Google\LandingUrlBuilder;
use App\Services\Agents\Google\SearchKeywordBuilder;
use App\Services\GoogleAds\CommonServices\ApplyConversionValueRules;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use App\Services\GoogleAds\SearchServices\CreateResponsiveSearchAd;
use App\Services\GoogleAds\SearchServices\CreateSearchAdGroup;
use App\Services\GoogleAds\SearchServices\CreateSearchCampaign;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SetupOnlySearchStagingTest extends TestCase
{
    use DatabaseTransactions;

    private array $writes = [];

    private array $statuses = [];

    private string $remoteBidding = 'MANUAL_CPC';

    private function fixture(array $customerAttributes = [], ?string $existing = null): array
    {
        $customer = Customer::factory()->create(array_merge([
            'google_ads_customer_id' => '123', 'service_type' => 'setup_only', 'setup_fee_paid_at' => now(),
        ], $customerAttributes));
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id, 'daily_budget' => '5.00', 'approved_daily_budget' => '5.00',
            'budget_confirmed_at' => now(), 'status' => 'draft',
        ]);
        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'daily_budget' => '5.00',
            'conversion_goals' => ['primary_goal' => 'Lead'],
            'bidding_strategy' => ['name' => 'MaximizeConversions'],
        ]);
        if ($existing) {
            $strategy->recordGoogleCampaignId($existing);
        }
        $strategy->adCopies()->create([
            'platform' => 'Google Ads', 'headlines' => ['Review Your Campaign', 'Your Ads Account', 'Keep Budget Control'],
            'descriptions' => ['Review the campaign we built for your business.', 'Start when your account is ready.'],
        ]);

        return [$customer, $campaign, $strategy];
    }

    private function executor(Customer $customer, ?AgentIssue $stepError = null, bool $goalsReady = false): SearchCampaignExecutor
    {
        $goals = $this->createMock(ReconcileCampaignConversionGoals::class);
        $readiness = ['ready' => $goalsReady, 'status' => $goalsReady ? 'ready' : 'needs_review',
            'intent' => ['category' => 'SUBMIT_LEAD_FORM'],
            'issues' => $goalsReady ? [] : [new AgentIssue('conversion_action_missing', 'No eligible Lead conversion action matches this objective.')]];
        $goals->method('prepare')->willReturn($readiness);
        $goals->expects($goalsReady ? $this->once() : $this->never())->method('reconcile')->willReturn($readiness);
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goals);
        $reader = $this->createMock(GetCampaignStatus::class);
        $reader->method('__invoke')->willReturnCallback(function () {
            $status = $this->statuses === [] ? GoogleStatus::PAUSED : array_shift($this->statuses);

            return $status === null ? null : ['status' => $status];
        });
        $this->app->bind(GetCampaignStatus::class, fn () => $reader);
        $configuration = $this->createMock(ReadCampaignConfiguration::class);
        $configuration->method('read')->willReturnCallback(fn () => ['campaign' => ['campaign' => ['biddingStrategyType' => $this->remoteBidding]]]);
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $configuration);
        $creator = $this->createMock(CreateSearchCampaign::class);
        $creator->method('__invoke')->willReturnCallback(function ($id, $data) {
            $this->writes['campaign'][] = $data;

            return 'customers/123/campaigns/456';
        });
        $this->app->bind(CreateSearchCampaign::class, fn () => $creator);
        $adGroup = $this->createMock(CreateSearchAdGroup::class);
        $adGroup->method('__invoke')->willReturnCallback(function () {
            $this->writes['ad_group'][] = true;

            return 'customers/123/adGroups/789';
        });
        $this->app->bind(CreateSearchAdGroup::class, fn () => $adGroup);
        $ad = $this->createMock(CreateResponsiveSearchAd::class);
        $ad->method('__invoke')->willReturnCallback(function ($id, $group, $data) {
            $this->writes['ad'][] = $data;

            return 'customers/123/adGroupAds/789~101';
        });
        $this->app->bind(CreateResponsiveSearchAd::class, fn () => $ad);
        $valueRules = $this->createMock(ApplyConversionValueRules::class);
        $valueRules->expects($this->never())->method('__invoke');
        $this->app->bind(ApplyConversionValueRules::class, fn () => $valueRules);
        $geo = $this->createMock(GeoTargetResolver::class);
        $geo->method('addLocationTargeting')->willReturnCallback(function ($id, $resource, $campaign, $strategy, $plan, $result) use ($stepError) {
            $this->writes['geo'][] = true;
            if ($stepError) {
                $result->addError($stepError->code, $stepError->message);
            }
        });
        $extensions = $this->createMock(AdExtensionBuilder::class);
        $extensions->method('createAndLinkAdExtensions')->willReturnCallback(function () {
            $this->writes['extensions'][] = true;
        });
        $urls = $this->createMock(LandingUrlBuilder::class);
        $urls->method('getFinalUrl')->willReturn('https://example.test/');
        $bidding = $this->createMock(BiddingStrategyApplier::class);
        $bidding->expects($this->never())->method('applyBiddingStrategy');
        $keywords = $this->createMock(SearchKeywordBuilder::class);
        $selected = [['text' => 'campaign setup', 'match_type' => 'EXACT']];
        $keywords->method('getKeywords')->willReturn($selected);
        $keywords->method('validateAndEnrichKeywords')->willReturn($selected);
        $keywords->method('addKeywords')->willReturnCallback(function () {
            $this->writes['keywords'][] = true;
        });
        $keywords->expects($this->never())->method('preflightReach');
        $audiences = $this->createMock(AudienceTargeter::class);

        return new SearchCampaignExecutor($customer, $geo, $extensions, $urls, $bidding, $keywords, $audiences);
    }

    public function test_paid_setup_creates_the_reviewed_assets_paused_without_claiming_activation_readiness(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $result = ExecutionResult::success();

        $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertTrue($result->success, $result->errorMessage());
        $this->assertSame('PAUSED', $this->writes['campaign'][0]['status']);
        $this->assertCount(1, $this->writes['ad_group']);
        $this->assertCount(1, $this->writes['keywords']);
        $this->assertCount(1, $this->writes['ad']);
        $this->assertSame('setup_only_paused_handover', $result->metadata['google_search_staging']['mode']);
        $this->assertFalse($result->metadata['google_search_staging']['enable_after_success']);
        $this->assertSame('PAUSED', $result->metadata['google_search_staging']['verified_platform_status']);
        $this->assertFalse($result->metadata['conversion_goal_readiness']['ready']);
        $this->assertSame('SUBMIT_LEAD_FORM', $result->metadata['conversion_goal_readiness']['intent']['category']);
        $this->assertFalse($result->metadata['search_reach_preflight']['gate']['ready']);
        $this->assertSame('not_checked', $result->metadata['search_reach_preflight']['status']);
        $this->assertSame('MANUAL_CPC', $result->metadata['google_search_baseline']['bidding']);
        $this->assertSame('Lead', $strategy->fresh()->conversion_goals['primary_goal']);
        $this->assertContains('conversion_action_missing', array_column($result->warnings, 'code'));
    }

    public function test_ready_goal_settings_do_not_apply_smart_bidding_or_value_rules_before_website_delivery_is_tested(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $result = ExecutionResult::success();

        $this->executor($customer, goalsReady: true)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertTrue($result->success, $result->errorMessage());
        $this->assertSame('MANUAL_CPC', $result->metadata['google_search_baseline']['bidding']);
        $this->assertFalse($result->metadata['google_search_staging']['enable_after_success']);
        $this->assertFalse($result->metadata['search_reach_preflight']['gate']['ready']);
    }

    #[DataProvider('strictCustomers')]
    public function test_unready_goals_still_block_non_setup_or_unpaid_customers(array $attributes): void
    {
        [$customer, $campaign, $strategy] = $this->fixture($attributes);
        $result = ExecutionResult::success();

        $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertFalse($result->success);
        $this->assertSame([], $this->writes);
        $this->assertSame('conversion_action_missing', $result->errors[0]->code);
        $this->assertArrayNotHasKey('google_search_staging', $result->metadata);
    }

    public static function strictCustomers(): array
    {
        return ['managed' => [['service_type' => 'managed']], 'unpaid setup' => [['setup_fee_paid_at' => null]]];
    }

    #[DataProvider('unsafeStatuses')]
    public function test_unverified_or_enabled_existing_campaigns_receive_no_staging_writes(?int $status): void
    {
        [$customer, $campaign, $strategy] = $this->fixture(existing: 'customers/123/campaigns/456');
        $this->statuses = [$status];
        $result = ExecutionResult::success();

        $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertFalse($result->success);
        $this->assertSame([], $this->writes);
        $this->assertContains('search_staging_pause_required', array_column($result->errors, 'code'));
    }

    public static function unsafeStatuses(): array
    {
        return ['enabled' => [GoogleStatus::ENABLED], 'unknown' => [null]];
    }

    public function test_existing_smart_bidding_is_held_instead_of_relabelled_as_manual_cpc(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture(existing: 'customers/123/campaigns/456');
        $this->remoteBidding = 'MAXIMIZE_CONVERSIONS';
        $result = ExecutionResult::success();

        $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertFalse($result->success);
        $this->assertSame([], $this->writes);
        $this->assertArrayNotHasKey('google_search_baseline', $result->metadata);
        $this->assertContains('setup_search_manual_bidding_required', array_column($result->errors, 'code'));
    }

    public function test_a_resource_in_another_account_cannot_receive_staging_writes(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture(existing: 'customers/999/campaigns/456');
        $result = ExecutionResult::success();

        $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertFalse($result->success);
        $this->assertSame([], $this->writes);
        $this->assertContains('setup_search_staging_account_mismatch', array_column($result->errors, 'code'));
    }

    public function test_ended_campaigns_cannot_use_the_paused_setup_exception(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $campaign->update(['status' => 'ended']);
        $result = ExecutionResult::success();

        $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertFalse($result->success);
        $this->assertSame([], $this->writes);
        $this->assertContains('setup_search_staging_authorization_changed', array_column($result->errors, 'code'));
    }

    public function test_deferred_activation_does_not_erase_an_unrelated_execution_error(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $result = ExecutionResult::success();

        $this->executor($customer, new AgentIssue('geo_write_failed', 'Targeting could not be applied.'))
            ->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertFalse($result->success);
        $this->assertContains('geo_write_failed', array_column($result->errors, 'code'));
    }

    public function test_a_campaign_enabled_during_construction_is_held_before_rsa_creation(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture();
        $this->statuses = [GoogleStatus::PAUSED, GoogleStatus::ENABLED];
        $result = ExecutionResult::success();

        $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertFalse($result->success);
        $this->assertArrayNotHasKey('ad', $this->writes);
        $this->assertContains('search_staging_pause_required', array_column($result->errors, 'code'));
    }
}
