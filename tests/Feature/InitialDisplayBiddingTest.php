<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\AdExtensionBuilder;
use App\Services\Agents\Google\BiddingStrategyApplier;
use App\Services\Agents\Google\Executors\DisplayCampaignExecutor;
use App\Services\Agents\Google\GeoTargetResolver;
use App\Services\Agents\Google\LandingUrlBuilder;
use App\Services\Agents\GoogleAdsExecutionAgent;
use App\Services\Agents\ValidationResult;
use App\Services\Deployment\AdDestinationCheck;
use App\Services\Deployment\GoogleAdsDeploymentStrategy;
use App\Services\GoogleAds\CommonServices\ApplyConversionValueRules;
use App\Services\GoogleAds\DisplayServices\CreateDisplayAdGroup;
use App\Services\GoogleAds\DisplayServices\CreateDisplayCampaign;
use App\Services\GoogleAds\DisplayServices\DisplayBiddingConfiguration;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InitialDisplayBiddingTest extends TestCase
{
    use DatabaseTransactions;

    private ?Customer $fixtureCustomer = null;

    private function fixture(array $bidding = [], array $campaignAttributes = []): array
    {
        $customer = $this->fixtureCustomer ??= Customer::factory()->create(['google_ads_customer_id' => '123', 'service_type' => 'managed']);
        $campaign = Campaign::factory()->create(array_merge(['customer_id' => $customer->id,
            'daily_budget' => '5.00', 'approved_daily_budget' => '5.00', 'budget_confirmed_at' => now()], $campaignAttributes));
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'campaign_type' => 'display', 'daily_budget' => '5.00', 'bidding_strategy' => $bidding]);

        return [$customer, $campaign, $strategy];
    }

    private function executor(Customer $customer, bool $expectGeo = false): DisplayCampaignExecutor
    {
        $geo = $this->createMock(GeoTargetResolver::class);
        $geo->expects($expectGeo ? $this->once() : $this->never())->method('addLocationTargeting');
        $extensions = $this->createMock(AdExtensionBuilder::class);
        $urls = $this->createMock(LandingUrlBuilder::class);
        $bidding = $this->createMock(BiddingStrategyApplier::class);
        $bidding->expects($this->never())->method('applyBiddingStrategy');

        return new DisplayCampaignExecutor($customer, $geo, $extensions, $urls, $bidding);
    }

    public static function unsupportedBidding(): array
    {
        return ['missing' => [[]], 'manual' => [['name' => 'ManualCpc']], 'unknown' => [['name' => 'GuessBidding']],
            'invalid_target' => [['name' => 'TargetCpa', 'parameters' => ['targetCpaMicros' => 0]]]];
    }

    #[DataProvider('unsupportedBidding')]
    public function test_display_without_supported_explicit_bidding_is_held_before_any_platform_write(array $bidding): void
    {
        [$customer, $campaign, $strategy] = $this->fixture($bidding);
        $result = ExecutionResult::success();
        $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertFalse($result->success);
        $this->assertSame('display_initial_bid_needs_review', $result->errors[0]->code);
        $this->assertSame([], $result->platformIds);
        $this->assertNull($strategy->fresh()->google_ads_campaign_id);
    }

    public function test_spend_hold_and_active_trial_cannot_be_bypassed_by_automated_display_bidding(): void
    {
        foreach ([['spend_safety_hold' => true], ['spend_guardrails' => ['enabled' => true, 'max_cpc_bid_micros' => 1_000_000]]] as $attributes) {
            [$customer, $campaign, $strategy] = $this->fixture(['name' => 'MaximizeConversions'], $attributes);
            $result = ExecutionResult::success();
            $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);
            $this->assertFalse($result->success);
            $this->assertSame('display_spending_changes_on_hold', $result->errors[0]->code);
            $this->assertNull($strategy->fresh()->google_ads_campaign_id);
        }
    }

    public function test_unready_goal_does_not_create_an_automated_display_campaign(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture(['name' => 'MaximizeConversions']);
        $goals = $this->createMock(ReconcileCampaignConversionGoals::class);
        $goals->expects($this->once())->method('prepare')->willReturn(['ready' => false, 'status' => 'needs_review', 'issues' => []]);
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goals);
        $result = ExecutionResult::success();

        $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertFalse($result->success);
        $this->assertSame('display_conversion_goal_needs_review', $result->errors[0]->code);
        $this->assertNull($strategy->fresh()->google_ads_campaign_id);
    }

    public static function unsafeRemoteBidding(): array
    {
        return [['ENABLED', 'MAXIMIZE_CONVERSIONS'], ['UNKNOWN', 'MAXIMIZE_CONVERSIONS'], ['PAUSED', 'MANUAL_CPC'], ['PAUSED', 'UNKNOWN']];
    }

    #[DataProvider('unsafeRemoteBidding')]
    public function test_retry_never_mutates_a_live_unknown_or_manual_cpc_display_campaign(string $status, string $bidding): void
    {
        [$customer, $campaign, $strategy] = $this->fixture(['name' => 'MaximizeConversions']);
        $strategy->recordGoogleCampaignId('customers/123/campaigns/61');
        $goals = $this->createMock(ReconcileCampaignConversionGoals::class);
        $goals->method('prepare')->willReturn(['ready' => true]);
        $goals->expects($this->never())->method('inspect');
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goals);
        $read = $this->createMock(DisplayBiddingConfiguration::class);
        $read->method('read')->willReturn(['campaign_resource' => 'customers/123/campaigns/61', 'status' => $status,
            'channel' => 'DISPLAY', 'bidding_strategy' => $bidding, 'daily_budget_micros' => 5_000_000, 'shared_budget' => false]);
        $this->app->bind(DisplayBiddingConfiguration::class, fn () => $read);
        $result = ExecutionResult::success();

        $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertFalse($result->success);
        $this->assertSame('display_initial_bidding_unverified', $result->errors[0]->code);
        $this->assertSame('customers/123/campaigns/61', $strategy->fresh()->google_ads_campaign_id);
    }

    public function test_new_display_uses_fresh_reviewed_allocation_and_automated_bidding_paused_with_actual_readback(): void
    {
        [$customer, $campaign, $strategy] = $this->fixture(['name' => 'MaximizeConversions']);
        $goals = $this->createMock(ReconcileCampaignConversionGoals::class);
        $goals->method('prepare')->willReturnCallback(function () use ($strategy): array {
            Strategy::whereKey($strategy->id)->update(['daily_budget' => '4.00']);

            return ['ready' => true];
        });
        $goals->method('inspect')->willReturn(['ready' => true]);
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goals);
        $create = $this->createMock(CreateDisplayCampaign::class);
        $create->expects($this->once())->method('__invoke')->willReturnCallback(function ($account, $data): string {
            $this->assertSame('123', $account);
            $this->assertSame('PAUSED', $data['status']);
            $this->assertSame('MAXIMIZE_CONVERSIONS', $data['biddingStrategyType']);
            $this->assertEquals(4, $data['budget']);

            return 'customers/123/campaigns/61';
        });
        $this->app->bind(CreateDisplayCampaign::class, fn () => $create);
        $read = $this->createMock(DisplayBiddingConfiguration::class);
        $read->method('read')->willReturn(['campaign_resource' => 'customers/123/campaigns/61', 'status' => 'PAUSED',
            'channel' => 'DISPLAY', 'bidding_strategy' => 'MAXIMIZE_CONVERSIONS', 'daily_budget_micros' => 4_000_000, 'shared_budget' => false]);
        $this->app->bind(DisplayBiddingConfiguration::class, fn () => $read);
        $group = $this->createMock(CreateDisplayAdGroup::class);
        $group->method('__invoke')->willReturn('customers/123/adGroups/7');
        $this->app->bind(CreateDisplayAdGroup::class, fn () => $group);
        $values = $this->createMock(ApplyConversionValueRules::class);
        $values->method('__invoke')->willReturn(true);
        $this->app->bind(ApplyConversionValueRules::class, fn () => $values);
        $result = ExecutionResult::success();

        $this->executor($customer, true)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertTrue($result->success, $result->errorMessage());
        $this->assertSame('MAXIMIZE_CONVERSIONS', $result->metadata['display_initial_bidding']['bidding_strategy']);
        $this->assertFalse($result->metadata['display_initial_bidding']['enable_after_success']);
        $this->assertFalse($result->metadata['display_initial_bidding']['activation_ready']);
    }

    public static function changedDuringRead(): array
    {
        return [['hold'], ['goal'], ['campaign_resource'], ['ad_group_resource'], ['allocation'], ['account']];
    }

    #[DataProvider('changedDuringRead')]
    public function test_changes_during_slow_goal_inspection_are_held_before_targeting_or_ads(string $change): void
    {
        [$customer, $campaign, $strategy] = $this->fixture(['name' => 'MaximizeConversions']);
        $strategy->recordGoogleCampaignId('customers/123/campaigns/61');
        $goals = $this->createMock(ReconcileCampaignConversionGoals::class);
        $goals->method('prepare')->willReturn(['ready' => true]);
        $goals->method('inspect')->willReturnCallback(function () use ($change, $customer, $campaign, $strategy): array {
            match ($change) {
                'hold' => Campaign::whereKey($campaign->id)->update(['spend_safety_hold' => ['reason' => 'owner_pause']]),
                'goal' => Strategy::whereKey($strategy->id)->update(['conversion_goals' => ['primary_goal' => 'Purchase']]),
                'campaign_resource' => Strategy::whereKey($strategy->id)->update(['google_ads_campaign_id' => 'customers/123/campaigns/62']),
                'ad_group_resource' => Strategy::whereKey($strategy->id)->update(['google_ads_ad_group_id' => 'customers/123/adGroups/99']),
                'allocation' => Strategy::whereKey($strategy->id)->update(['daily_budget' => '4.00']),
                'account' => Customer::whereKey($customer->id)->update(['google_ads_customer_id' => '456']),
                default => throw new \InvalidArgumentException('Unknown fixture change: '.$change),
            };

            return ['ready' => true];
        });
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goals);
        $read = $this->createMock(DisplayBiddingConfiguration::class);
        $read->method('read')->willReturn(['campaign_resource' => 'customers/123/campaigns/61', 'status' => 'PAUSED',
            'channel' => 'DISPLAY', 'bidding_strategy' => 'MAXIMIZE_CONVERSIONS', 'daily_budget_micros' => 5_000_000, 'shared_budget' => false]);
        $this->app->bind(DisplayBiddingConfiguration::class, fn () => $read);
        $result = ExecutionResult::success();

        $this->executor($customer)->execute('123', $campaign, $strategy, new ExecutionPlan([]), $result);

        $this->assertFalse($result->success);
        $this->assertSame($change === 'ad_group_resource' ? 'customers/123/adGroups/99' : null, $strategy->fresh()->google_ads_ad_group_id);
    }

    public function test_legacy_search_and_display_delegate_to_canonical_execution_and_preserve_its_failure(): void
    {
        $destination = $this->createMock(AdDestinationCheck::class);
        $destination->method('check')->willReturn(['status' => 'reachable']);
        $this->app->bind(AdDestinationCheck::class, fn () => $destination);
        foreach (['search', 'display'] as $type) {
            [$customer, $campaign, $strategy] = $this->fixture();
            $strategy->update(['campaign_type' => $type]);
            $agent = new class($customer) extends GoogleAdsExecutionAgent
            {
                public ?ExecutionContext $received = null;

                protected function validatePrerequisites(ExecutionContext $context): ValidationResult
                {
                    $this->received = $context;

                    return (new ValidationResult(true))->addError('initial_bid_needs_review', 'The initial bid estimate needs review.');
                }
            };
            $this->app->bind(GoogleAdsExecutionAgent::class, fn () => $agent);

            $this->assertFalse((new GoogleAdsDeploymentStrategy($customer))->deploy($campaign, $strategy));
            $this->assertSame($strategy->id, $agent->received?->strategy->id);
            $this->assertSame('The initial bid estimate needs review.', $strategy->fresh()->deployment_error);
            $this->assertSame('initial_bid_needs_review', $strategy->fresh()->execution_result['errors'][0]['code']);
            $this->assertNull($campaign->fresh()->google_ads_campaign_id);
        }
    }
}
