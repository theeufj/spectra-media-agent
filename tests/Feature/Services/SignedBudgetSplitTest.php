<?php

namespace Tests\Feature\Services;

use App\Models\Customer;
use App\Models\PlatformBudgetAllocation;
use App\Services\CrossChannelBudgetAllocator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SignedBudgetSplitTest extends TestCase
{
    use DatabaseTransactions;

    public function test_scheduler_records_only_a_proposal_and_manual_changes_require_the_exact_displayed_split(): void
    {
        $customer = Customer::factory()->create();
        $allocation = PlatformBudgetAllocation::create(['customer_id' => $customer->id, 'total_monthly_budget' => 1000, 'auto_rebalance' => true,
            'strategy' => 'equal', 'google_ads_pct' => 60, 'facebook_ads_pct' => 40, 'microsoft_ads_pct' => 0, 'linkedin_ads_pct' => 0]);
        $allocator = $this->getMockBuilder(CrossChannelBudgetAllocator::class)->onlyMethods(['getPerformanceSnapshot', 'getAiReasoning'])->getMock();
        $allocator->method('getPerformanceSnapshot')->willReturn(['evidence' => hash('sha256', 'reviewed data'), 'goal' => 'purchase', 'google_ads' => ['campaigns' => 1], 'facebook_ads' => ['campaigns' => 1],
            'microsoft_ads' => ['campaigns' => 0], 'linkedin_ads' => ['campaigns' => 0]]);
        $allocator->method('getAiReasoning')->willReturn(null);
        $proposal = $allocator->analyze($customer)['recommendations'];
        $scheduled = $allocator->rebalance($customer, 'scheduled');
        $this->assertSame('proposed', $scheduled['status']);
        $this->assertFalse($scheduled['log']->auto_executed);
        $this->assertEquals(60, $allocation->fresh()->google_ads_pct);
        $this->assertEquals(60, $scheduled['log']->after_allocation['google_ads_pct']);
        $this->assertSame('needs_approval', $allocator->rebalance($customer, 'manual')['status']);
        $allocation->update(['google_ads_pct' => 65, 'facebook_ads_pct' => 35]);
        $this->assertSame('needs_approval', $allocator->rebalance($customer, 'manual', $proposal['approval_fingerprint'])['status']);
        $fresh = $allocator->analyze($customer)['recommendations'];
        $this->assertSame('rebalanced', $allocator->rebalance($customer, 'manual', $fresh['approval_fingerprint'])['status']);
        $this->assertEquals(50, $allocation->fresh()->google_ads_pct);
        $this->assertEquals(50, $allocation->fresh()->facebook_ads_pct);
    }
}
