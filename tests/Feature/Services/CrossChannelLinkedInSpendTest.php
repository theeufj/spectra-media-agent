<?php

namespace Tests\Feature\Services;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\CrossChannelBudgetAllocator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression: LinkedIn spend was silently missing from every cross-channel
 * rebalance.
 *
 * getPerformanceSnapshot() gated the LinkedIn branch on
 * `$campaign->linkedin_ads_campaign_id`, matching the naming of the other three
 * platforms. That column does not exist — LinkedIn's is `linkedin_campaign_id`,
 * the one platform without the `_ads_` infix — so the check was always null and
 * the branch never ran.
 *
 * Nothing failed loudly. The snapshot simply reported LinkedIn at zero spend and
 * zero conversions, and WeeklyBudgetRebalance (Mondays 06:00) rebalanced real
 * budget against that. The error was frozen in phpstan-baseline.neon, which is
 * why it survived.
 */
class CrossChannelLinkedInSpendTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Customer::unsetEventDispatcher();  // creating a Customer provisions a real Google Ads account
    }

    private function snapshotFor(Customer $customer): array
    {
        $allocator = new CrossChannelBudgetAllocator;

        $method = new \ReflectionMethod($allocator, 'getPerformanceSnapshot');
        $method->setAccessible(true);

        return $method->invoke($allocator, $customer);
    }

    public function test_linkedin_spend_appears_in_the_performance_snapshot(): void
    {
        $customer = Customer::factory()->create();

        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'linkedin_campaign_id' => 'urn:li:sponsoredCampaign:123',
        ]);

        DB::table('linkedin_ads_performance_data')->insert([
            'campaign_id' => $campaign->id,
            'date' => now()->subDays(3)->toDateString(),
            'impressions' => 4000,
            'clicks' => 120,
            'cost' => 250.50,
            'conversions' => 8,
            'conversion_value' => 1600.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshot = $this->snapshotFor($customer);

        $this->assertSame(250.50, $snapshot['linkedin_ads']['spend'], 'LinkedIn spend was dropped from the snapshot');
        $this->assertSame(8.0, $snapshot['linkedin_ads']['conversions']);
        $this->assertSame(1600.0, $snapshot['linkedin_ads']['conversion_value']);
        $this->assertSame(120, $snapshot['linkedin_ads']['clicks']);
        $this->assertSame(1, $snapshot['linkedin_ads']['campaigns']);
    }

    public function test_a_campaign_never_deployed_to_linkedin_contributes_nothing(): void
    {
        $customer = Customer::factory()->create();

        // No linkedin_campaign_id: the branch must stay closed, or every
        // Google-only campaign would start reporting a LinkedIn arm.
        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'linkedin_campaign_id' => null,
        ]);

        $snapshot = $this->snapshotFor($customer);

        $this->assertSame(0, $snapshot['linkedin_ads']['campaigns']);
        $this->assertSame(0, $snapshot['linkedin_ads']['spend']);
    }
}
