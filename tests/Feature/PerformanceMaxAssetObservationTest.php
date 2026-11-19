<?php

namespace Tests\Feature;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\PMaxAssetOptimizationAgent;
use App\Services\GoogleAds\PerformanceMaxServices\GetAssetGroupPerformance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PerformanceMaxAssetObservationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reporting_uses_bounded_supported_metrics_and_preserves_unserved_assets(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123-456-7890']);
        $reader = new ObservedAssetPerformance($customer);
        $report = $reader('1234567890', 'customers/1234567890/campaigns/10', 'LAST_14_DAYS');
        $this->assertSame(['asset-0'], array_column($report['pending'], 'asset_resource'));
        $this->assertSame(['asset-1'], array_column($report['learning'], 'asset_resource'));
        $this->assertSame(['asset-3'], array_column($report['low'], 'asset_resource'));
        $this->assertSame(['asset-2', 'asset-4', 'asset-5'], array_column($report['observed'], 'asset_resource'));
        $this->assertSame(2_000_000, $report['observed'][0]['cost_micros']);
        $this->assertCount(2, $reader->queries);
        $this->assertStringNotContainsString('performance_label', implode(' ', $reader->queries));
        $this->assertStringContainsString('segments.date DURING LAST_14_DAYS', $reader->queries[1]);
        $this->assertStringContainsString("asset_group.status='ENABLED'", $reader->queries[0]);
    }

    public function test_cross_account_resource_is_rejected_before_query(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123-456-7890']);
        $this->expectException(\InvalidArgumentException::class);
        (new ObservedAssetPerformance($customer))('1234567890', 'customers/9876543210/campaigns/10');
    }

    public function test_the_agent_persists_observation_and_never_mutates_assets(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123-456-7890']);
        $campaign = Campaign::factory()->for($customer)->create(['google_ads_campaign_id' => '10']);
        $this->app->bind(GetAssetGroupPerformance::class, fn () => new ObservedAssetPerformance($customer));
        $result = (new PMaxAssetOptimizationAgent($customer))->run($campaign);
        $this->assertSame(0, $result['text_replaced']);
        $this->assertSame(1, $result['text_flagged']);
        $this->assertSame(0, $result['image_flagged']);
        $this->assertSame(2, $result['insufficient_data_assets']);
        $log = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'asset_performance_observed')->firstOrFail();
        $this->assertSame(1, $log->details['no_delivery_assets']);
        $this->assertSame(5000, $log->details['highest_asset_impressions']);
        $this->assertSame('asset-3', $log->details['review_candidates'][0]['asset_resource']);
    }
}

final class ObservedAssetPerformance extends GetAssetGroupPerformance
{
    public array $queries = [];

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    protected function rows(string $customerId, string $query): array
    {
        $this->queries[] = $query;
        $rows = [];
        $samples = [['HEADLINE', 0, 0, 0, 0], ['DESCRIPTION', 12, 0, 0, 400_000],
            ['MARKETING_IMAGE', 2_500, 0, 0, 2_000_000], ['LONG_HEADLINE', 2_500, 0, 0, 60_000_000],
            ['LOGO', 5_000, 0, 0, 90_000_000], ['DESCRIPTION', 5_000, 0, 1, 80_000_000]];
        foreach ($samples as $index => [$type, $impressions, $clicks, $conversions, $cost]) {
            $link = ['resourceName' => 'association-'.$index, 'asset' => 'asset-'.$index, 'fieldType' => $type, 'primaryStatus' => 'ELIGIBLE'];
            if (str_contains($query, 'metrics.impressions')) {
                if ($impressions) {
                    $rows[] = ['assetGroupAsset' => $link, 'metrics' => ['impressions' => $impressions, 'clicks' => $clicks, 'costMicros' => $cost, 'conversions' => $conversions]];
                }
            } else {
                $rows[] = ['assetGroupAsset' => $link, 'assetGroup' => ['resourceName' => 'group']];
            }
        }

        return $rows;
    }
}
