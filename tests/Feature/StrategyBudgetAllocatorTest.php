<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\PlatformBudgetAllocation;
use App\Models\Strategy;
use App\Services\Agents\ExecutionBudgetGuard;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionPlan;
use App\Services\Deployment\StrategyBudgetAllocator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StrategyBudgetAllocatorTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(string $budget = '50.00'): Campaign
    {
        return Campaign::factory()->create([
            'approved_daily_budget' => $budget,
            'daily_budget' => $budget,
            'auto_generated_at' => null,
        ]);
    }

    private function strategy(Campaign $campaign, string $platform = 'Google Ads', array $attributes = []): Strategy
    {
        return Strategy::factory()->create(array_merge([
            'campaign_id' => $campaign->id,
            'platform' => $platform,
            'signed_off_at' => now(),
            'daily_budget' => null,
        ], $attributes));
    }

    private function weights(Campaign $campaign, array $percentages): void
    {
        PlatformBudgetAllocation::create(array_merge([
            'customer_id' => $campaign->customer_id,
            'total_monthly_budget' => '1500.00',
            'strategy' => 'performance',
        ], $percentages));
    }

    private function assertAuthorized(Campaign $campaign): void
    {
        foreach ($campaign->strategies as $strategy) {
            $result = (new ExecutionBudgetGuard)->validate(
                new ExecutionPlan([]),
                new ExecutionContext($strategy, $campaign, $campaign->customer),
                $strategy->platform,
            );
            $this->assertTrue($result->passed, $result->errorMessage());
        }
    }

    public function test_three_way_split_does_not_create_an_extra_cent_or_block_deployment(): void
    {
        $campaign = $this->campaign();
        $first = $this->strategy($campaign);
        $second = $this->strategy($campaign, 'Facebook Ads');
        $third = $this->strategy($campaign, 'Microsoft Ads');

        (new StrategyBudgetAllocator)->allocate($campaign, collect([$third, $second, $first]));

        $this->assertEquals('16.67', $first->daily_budget);
        $this->assertEquals('16.67', $second->daily_budget);
        $this->assertEquals('16.66', $third->daily_budget);
        $this->assertEquals('50.00', $campaign->strategies()->sum('daily_budget'));
        $this->assertAuthorized($campaign);
    }

    public function test_weighted_half_cent_remainders_are_distributed_within_the_approval(): void
    {
        $campaign = $this->campaign('50.01');
        $first = $this->strategy($campaign);
        $second = $this->strategy($campaign, 'Facebook Ads');
        $this->weights($campaign, ['google_ads_pct' => '50.00', 'facebook_ads_pct' => '50.00']);

        (new StrategyBudgetAllocator)->allocate($campaign, collect([$second, $first]));

        $this->assertEquals('25.01', $first->daily_budget);
        $this->assertEquals('25.00', $second->daily_budget);
        $this->assertAuthorized($campaign);
    }

    public function test_default_platform_weights_cannot_assign_zero_to_an_approved_sibling(): void
    {
        $campaign = $this->campaign();
        $google = $this->strategy($campaign);
        $microsoft = $this->strategy($campaign, 'Microsoft Ads');
        $this->weights($campaign, ['google_ads_pct' => '100.00', 'microsoft_ads_pct' => '0.00']);

        (new StrategyBudgetAllocator)->allocate($campaign, collect([$google, $microsoft]));

        $this->assertEquals('25.00', $google->daily_budget);
        $this->assertEquals('25.00', $microsoft->daily_budget);
        $this->assertAuthorized($campaign);
    }

    public function test_unknown_live_allocations_are_not_invented_to_authorize_more_spending(): void
    {
        $campaign = $this->campaign();
        $live = $this->strategy($campaign, attributes: ['deployment_status' => 'active']);
        $selected = $this->strategy($campaign, 'Microsoft Ads');

        (new StrategyBudgetAllocator)->allocate($campaign, collect([$selected]));

        $this->assertNull($live->fresh()->daily_budget);
        $this->assertNull($selected->fresh()->daily_budget);
        $result = (new ExecutionBudgetGuard)->validate(new ExecutionPlan([]), new ExecutionContext($selected, $campaign, $campaign->customer), 'Microsoft Ads');
        $this->assertFalse($result->passed);
    }

    public function test_requeued_existing_campaigns_with_unknown_allocations_still_need_review(): void
    {
        $campaign = $this->campaign();
        $live = $this->strategy($campaign, attributes: ['deployment_status' => 'queued', 'deployed_at' => now()]);
        $selected = $this->strategy($campaign, 'Microsoft Ads');

        (new StrategyBudgetAllocator)->allocate($campaign, collect([$selected]));

        $this->assertNull($live->fresh()->daily_budget);
        $this->assertNull($selected->fresh()->daily_budget);
    }

    public function test_plan_filtered_prior_deployments_still_reserve_their_entire_allocation(): void
    {
        $campaign = $this->campaign();
        $prior = $this->strategy($campaign, attributes: ['daily_budget' => '50.00', 'deployment_status' => 'skipped_plan', 'deployed_at' => now()]);
        $prior->forceFill(['google_ads_campaign_id' => '123'])->save();
        $selected = $this->strategy($campaign, 'Microsoft Ads');

        (new StrategyBudgetAllocator)->allocate($campaign, collect([$selected]));

        $this->assertEquals('50.00', $prior->fresh()->daily_budget);
        $this->assertEquals('0.00', $selected->fresh()->daily_budget);
        $result = (new ExecutionBudgetGuard)->validate(new ExecutionPlan([]), new ExecutionContext($selected, $campaign, $campaign->customer), 'Microsoft Ads');
        $this->assertFalse($result->passed);
    }

    public function test_unused_approval_can_still_fund_a_new_strategy_beside_a_plan_filtered_prior_deployment(): void
    {
        $campaign = $this->campaign();
        $prior = $this->strategy($campaign, attributes: ['daily_budget' => '30.00', 'deployment_status' => 'skipped_plan', 'signed_off_at' => null]);
        $prior->forceFill(['google_ads_campaign_id' => '123'])->save();
        $selected = $this->strategy($campaign, 'Microsoft Ads');

        (new StrategyBudgetAllocator)->allocate($campaign, collect([$selected]));

        $this->assertEquals('30.00', $prior->fresh()->daily_budget);
        $this->assertEquals('20.00', $selected->fresh()->daily_budget);
        $result = (new ExecutionBudgetGuard)->validate(new ExecutionPlan([]), new ExecutionContext($selected, $campaign, $campaign->customer), 'Microsoft Ads');
        $this->assertTrue($result->passed, $result->errorMessage());
    }

    public function test_selected_strategy_and_missing_signed_off_siblings_share_only_the_remaining_approval(): void
    {
        $campaign = $this->campaign();
        $existing = $this->strategy($campaign, attributes: ['daily_budget' => '20.00']);
        $selected = $this->strategy($campaign, 'Facebook Ads');
        $sibling = $this->strategy($campaign, 'Microsoft Ads');

        (new StrategyBudgetAllocator)->allocate($campaign, collect([$selected]));

        $this->assertEquals('20.00', $existing->fresh()->daily_budget);
        $this->assertEquals('15.00', $selected->daily_budget);
        $this->assertEquals('15.00', $sibling->fresh()->daily_budget);
        $this->assertAuthorized($campaign);
    }

    public function test_weighted_allocation_reserves_existing_budgets_before_splitting_the_remainder(): void
    {
        $campaign = $this->campaign();
        $existing = $this->strategy($campaign, attributes: ['daily_budget' => '30.00']);
        $facebook = $this->strategy($campaign, 'Facebook Ads');
        $microsoft = $this->strategy($campaign, 'Microsoft Ads');
        $this->weights($campaign, ['google_ads_pct' => '20.00', 'facebook_ads_pct' => '40.00', 'microsoft_ads_pct' => '40.00']);

        (new StrategyBudgetAllocator)->allocate($campaign, collect([$facebook, $microsoft]));

        $this->assertEquals('30.00', $existing->fresh()->daily_budget);
        $this->assertEquals('10.00', $facebook->daily_budget);
        $this->assertEquals('10.00', $microsoft->daily_budget);
        $this->assertAuthorized($campaign);
    }

    public function test_live_budgets_are_reserved_and_unapproved_or_plan_filtered_drafts_are_untouched(): void
    {
        $campaign = $this->campaign();
        $live = $this->strategy($campaign, attributes: ['daily_budget' => '30.00', 'signed_off_at' => null, 'deployment_status' => 'active']);
        $draft = $this->strategy($campaign, attributes: ['daily_budget' => '100.00', 'signed_off_at' => null]);
        $skipped = $this->strategy($campaign, attributes: ['daily_budget' => '100.00', 'deployment_status' => 'skipped_plan']);
        $selected = $this->strategy($campaign, 'Facebook Ads');

        (new StrategyBudgetAllocator)->allocate($campaign, collect([$selected]));

        $this->assertEquals('30.00', $live->fresh()->daily_budget);
        $this->assertEquals('100.00', $draft->fresh()->daily_budget);
        $this->assertEquals('100.00', $skipped->fresh()->daily_budget);
        $this->assertEquals('20.00', $selected->daily_budget);
        $result = (new ExecutionBudgetGuard)->validate(new ExecutionPlan([]), new ExecutionContext($selected, $campaign, $campaign->customer), 'Facebook Ads');
        $this->assertTrue($result->passed, $result->errorMessage());
    }

    public function test_weighted_minimums_cannot_mint_money_when_the_approval_is_small(): void
    {
        $campaign = $this->campaign('0.05');
        $first = $this->strategy($campaign);
        $second = $this->strategy($campaign, 'Facebook Ads');
        $this->weights($campaign, ['google_ads_pct' => '50.00', 'facebook_ads_pct' => '50.00']);

        (new StrategyBudgetAllocator)->allocate($campaign, collect([$first, $second]));

        $this->assertEquals('0.03', $first->daily_budget);
        $this->assertEquals('0.02', $second->daily_budget);
        $this->assertEquals('0.05', $campaign->strategies()->sum('daily_budget'));
    }

    public function test_allocations_stop_at_approval_when_the_desired_budget_was_raised_without_approval(): void
    {
        $campaign = $this->campaign();
        $campaign->update(['daily_budget' => '100.00']);
        $strategy = $this->strategy($campaign);

        (new StrategyBudgetAllocator)->allocate($campaign, collect([$strategy]));

        $this->assertEquals('50.00', $strategy->daily_budget);
        $this->assertAuthorized($campaign);
    }

    public function test_a_lower_current_budget_is_respected_without_expanding_to_the_approval(): void
    {
        $campaign = $this->campaign();
        $campaign->update(['daily_budget' => '30.00']);
        $strategy = $this->strategy($campaign);

        (new StrategyBudgetAllocator)->allocate($campaign, collect([$strategy]));

        $this->assertEquals('30.00', $strategy->daily_budget);
    }

    public function test_existing_excess_allocations_are_preserved_for_review_and_cannot_fund_another_strategy(): void
    {
        $campaign = $this->campaign();
        $existing = $this->strategy($campaign, attributes: ['daily_budget' => '60.00']);
        $selected = $this->strategy($campaign, 'Facebook Ads');

        (new StrategyBudgetAllocator)->allocate($campaign, collect([$selected]));

        $this->assertEquals('60.00', $existing->fresh()->daily_budget);
        $this->assertNull($selected->fresh()->daily_budget);
    }

    public function test_retries_preserve_the_same_allocations(): void
    {
        $campaign = $this->campaign();
        $first = $this->strategy($campaign);
        $second = $this->strategy($campaign, 'Facebook Ads');
        $third = $this->strategy($campaign, 'Microsoft Ads');
        $allocator = new StrategyBudgetAllocator;
        $allocator->allocate($campaign, collect([$first, $second, $third]));
        $expected = $campaign->strategies()->orderBy('id')->pluck('daily_budget')->all();

        $allocator->allocate($campaign, collect([$third]));

        $this->assertSame($expected, $campaign->strategies()->orderBy('id')->pluck('daily_budget')->all());
    }
}
