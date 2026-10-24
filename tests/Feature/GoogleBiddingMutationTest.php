<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBiddingStrategy;
use Google\Ads\GoogleAds\V26\Services\CampaignOperation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleBiddingMutationTest extends TestCase
{
    use DatabaseTransactions;

    private function writer(string $strategy, array $overrides = []): VerifiedBiddingWriter
    {
        $writer = new VerifiedBiddingWriter(new Customer(['google_ads_customer_id' => '123']));
        $writer->after = $overrides + ['resource' => 'customers/123/campaigns/456', 'strategy' => $strategy, 'portfolio' => '',
            'target_cpa_micros' => 0, 'target_roas' => 0.0, 'cpc_bid_ceiling_micros' => 0];

        return $writer;
    }

    public static function supportedStrategies(): array
    {
        return [['MAXIMIZE_CONVERSIONS', null, null, null], ['TARGET_CPA', 18.25, null, null],
            ['TARGET_ROAS', null, 4.5, null], ['MAXIMIZE_CONVERSION_VALUE', null, null, null], ['MAXIMIZE_CLICKS', null, null, 2_000_000]];
    }

    #[DataProvider('supportedStrategies')]
    public function test_supported_strategy_uses_exact_configuration_and_confirmed_readback(string $strategy, ?float $cpa, ?float $roas, ?int $cap): void
    {
        $writer = $this->writer($strategy, ['target_cpa_micros' => (int) round(($cpa ?? 0) * 1_000_000),
            'target_roas' => $roas ?? 0.0, 'cpc_bid_ceiling_micros' => $cap ?? 0]);
        $this->assertTrue($writer('123', 'customers/123/campaigns/456', $strategy, $cpa, $roas, $cap));
        $this->assertSame(1, $writer->reads);
        $update = $writer->writes[0]->getUpdate();
        $this->assertFalse($update->hasManualCpc());
        $this->assertSame('customers/123/campaigns/456', $update->getResourceName());
        if (in_array($strategy, ['TARGET_CPA', 'MAXIMIZE_CONVERSIONS'], true)) {
            $this->assertSame((int) round(($cpa ?? 0) * 1_000_000), $update->getMaximizeConversions()->getTargetCpaMicros());
            $this->assertSame(['maximize_conversions.target_cpa_micros'], iterator_to_array($writer->writes[0]->getUpdateMask()->getPaths()));
        } elseif (in_array($strategy, ['TARGET_ROAS', 'MAXIMIZE_CONVERSION_VALUE'], true)) {
            $this->assertEquals($roas ?? 0, $update->getMaximizeConversionValue()->getTargetRoas());
        } else {
            $this->assertSame($cap, $update->getTargetSpend()->getCpcBidCeilingMicros());
        }
    }

    public function test_retired_strategy_missing_targets_and_wrong_account_never_mutate(): void
    {
        foreach ([['ENHANCED_CPC', null, null], ['TARGET_CPA', null, null], ['TARGET_ROAS', null, null],
            ['TARGET_CPA', 0.0, null], ['TARGET_ROAS', null, INF]] as [$strategy, $cpa, $roas]) {
            $writer = $this->writer($strategy);
            $this->assertFalse($writer('123', 'customers/123/campaigns/456', $strategy, $cpa, $roas));
            $this->assertSame([], $writer->writes);
        }
        $writer = $this->writer('MAXIMIZE_CONVERSIONS');
        $this->assertFalse($writer('123', 'customers/999/campaigns/456', 'MAXIMIZE_CONVERSIONS'));
        $this->assertSame([], $writer->writes);
    }

    public function test_missing_wrong_or_unconfirmed_readback_is_not_reported_as_success(): void
    {
        foreach ([null, ['strategy' => 'MANUAL_CPC'], ['target_cpa_micros' => 19_000_000], ['portfolio' => 'customers/123/biddingStrategies/789']] as $after) {
            $writer = $this->writer('TARGET_CPA', ['target_cpa_micros' => 18_000_000]);
            $writer->after = $after === null ? null : array_replace($writer->after, $after);
            $this->assertFalse($writer('123', 'customers/123/campaigns/456', 'TARGET_CPA', 18.0));
        }
    }

    public function test_dry_run_validates_without_claiming_a_live_readback(): void
    {
        $writer = $this->writer('MAXIMIZE_CONVERSIONS');
        $writer->after = null;
        $this->assertTrue($writer->dryRun()('123', 'customers/123/campaigns/456', 'MAXIMIZE_CONVERSIONS'));
        $this->assertSame(0, $writer->reads);
    }
}

class VerifiedBiddingWriter extends UpdateCampaignBiddingStrategy
{
    public array $writes = [];

    public ?array $after = null;

    public int $reads = 0;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    protected function ensureClient(): void {}

    protected function mutate(string $customerId, CampaignOperation $operation): bool
    {
        $this->writes[] = $operation;

        return true;
    }

    public function readBiddingState(string $customerId, string $resource): ?array
    {
        $this->reads++;

        return $this->after;
    }
}
