<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\GoogleAds\ConversionTrackingService;
use Google\Ads\GoogleAds\V22\Common\Metrics;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V22\Enums\ConversionActionStatusEnum\ConversionActionStatus;
use Google\Ads\GoogleAds\V22\Resources\Campaign as GoogleCampaign;
use Google\Ads\GoogleAds\V22\Resources\ConversionAction;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Google\ApiCore\PagedListResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ConversionTrackingServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function reader(array $rows = []): ConversionTrackingReaderFixture
    {
        return new ConversionTrackingReaderFixture(
            new Customer(['google_ads_customer_id' => '123-456-7890']), $rows,
        );
    }

    private function metrics(float $conversions, float $allConversions = 0.0, int $clicks = 0, int $impressions = 0): GoogleAdsRow
    {
        return new GoogleAdsRow(['metrics' => new Metrics([
            'conversions' => $conversions, 'all_conversions' => $allConversions,
            'clicks' => $clicks, 'impressions' => $impressions,
        ])]);
    }

    public function test_account_activity_preserves_fractional_attribution_and_traffic(): void
    {
        $reader = $this->reader([$this->metrics(0.3, 0.7, 3, 20), $this->metrics(0.2, 0.8, 4, 40)]);
        $activity = $reader->getAccountActivityLast30Days('123-456-7890');

        $this->assertSame(0.5, $activity['conversions']);
        $this->assertSame(1.5, $activity['all_conversions']);
        $this->assertSame(7, $activity['clicks']);
        $this->assertSame(60, $activity['impressions']);
        $this->assertSame('LAST_30_DAYS', $activity['period']);
        $this->assertSame('1234567890', $reader->queries[0]['customer_id']);
        $this->assertStringContainsString('FROM customer WHERE segments.date DURING LAST_30_DAYS', $reader->queries[0]['query']);
        $this->assertSame(1, $reader->clientChecks);
    }

    public function test_integer_bidding_count_floors_after_aggregating_fractional_values(): void
    {
        $reader = $this->reader([$this->metrics(0.7), $this->metrics(0.7)]);
        $this->assertSame(1, $reader->getConversionCountLast30Days('1234567890'));
    }

    public function test_successful_empty_metrics_are_zero_activity_without_proving_tracking(): void
    {
        $this->assertSame([
            'period' => 'LAST_30_DAYS', 'conversions' => 0.0, 'all_conversions' => 0.0,
            'clicks' => 0, 'impressions' => 0,
        ], $this->reader()->getAccountActivityLast30Days('1234567890'));
    }

    public function test_account_activity_failures_are_not_converted_into_zero(): void
    {
        $reader = $this->reader();
        $reader->failure = new \RuntimeException('Google is unavailable');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google is unavailable');
        $reader->getAccountActivityLast30Days('1234567890');
    }

    public function test_partial_iterator_failure_cannot_return_a_partial_conversion_count(): void
    {
        $reader = $this->reader([$this->metrics(1.2)]);
        $reader->iterationFailure = new \RuntimeException('Second page failed');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Second page failed');
        $reader->getConversionCountLast30Days('1234567890');
    }

    public function test_missing_client_is_unknown_instead_of_zero(): void
    {
        $reader = $this->reader();
        $reader->missingClient = true;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No Google client');
        $reader->getConversionCountLast30Days('1234567890');
    }

    public function test_mismatched_or_malformed_accounts_are_rejected_before_querying(): void
    {
        foreach (['', '9876543210', '1234567890x', "1234567890' OR 1=1"] as $accountId) {
            $reader = $this->reader();
            try {
                $reader->getAccountActivityLast30Days($accountId);
                $this->fail('Unexpected account evidence query.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('current customer', $e->getMessage());
                $this->assertSame([], $reader->queries);
            }
        }
    }

    public function test_setup_lookup_requires_an_enabled_action(): void
    {
        $reader = $this->reader([new GoogleAdsRow(['conversion_action' => new ConversionAction([
            'id' => 1, 'status' => ConversionActionStatus::REMOVED,
        ])])]);
        $this->assertFalse($reader->isConversionTrackingSetUp('1234567890'));
        $this->assertStringContainsString("conversion_action.status = 'ENABLED'", $reader->queries[0]['query']);

        $reader = $this->reader([new GoogleAdsRow(['conversion_action' => new ConversionAction([
            'id' => 2, 'status' => ConversionActionStatus::ENABLED,
        ])])]);
        $this->assertTrue($reader->isConversionTrackingSetUp('1234567890'));
        $this->assertFalse($this->reader()->isConversionTrackingSetUp('1234567890'));
    }

    public function test_setup_lookup_failure_is_not_treated_as_missing_actions(): void
    {
        $reader = $this->reader();
        $reader->failure = new \RuntimeException('Action lookup failed');
        $this->expectException(\RuntimeException::class);
        $reader->isConversionTrackingSetUp('1234567890');
    }

    public function test_campaign_conversion_evidence_is_scoped_to_the_exact_campaign_and_action(): void
    {
        $reader = $this->reader([$this->metrics(0.4, 0.8), $this->metrics(0.2, 0.4)]);
        $activity = $reader->getCampaignActionConversionsLast30Days(
            '1234567890', 'customers/1234567890/campaigns/61', 'customers/1234567890/conversionActions/77',
        );

        $this->assertEqualsWithDelta(0.6, $activity['conversions'], 0.00001);
        $this->assertEqualsWithDelta(1.2, $activity['all_conversions'], 0.00001);
        $this->assertStringStartsWith('SELECT segments.conversion_action,', $reader->queries[0]['query']);
        $this->assertStringContainsString("campaign.resource_name = 'customers/1234567890/campaigns/61'", $reader->queries[0]['query']);
        $this->assertStringContainsString("segments.conversion_action = 'customers/1234567890/conversionActions/77'", $reader->queries[0]['query']);
        $this->assertStringContainsString('segments.date DURING LAST_30_DAYS', $reader->queries[0]['query']);
    }

    public function test_resources_from_another_account_or_collection_cannot_supply_evidence(): void
    {
        foreach ([
            ['customers/9876543210/campaigns/61', 'customers/1234567890/conversionActions/77'],
            ['customers/1234567890/campaigns/61', 'customers/9876543210/conversionActions/77'],
            ['customers/1234567890/adGroups/61', 'customers/1234567890/conversionActions/77'],
            ['customers/1234567890/campaigns/61', "customers/1234567890/conversionActions/77'"],
        ] as [$campaignResource, $actionResource]) {
            $reader = $this->reader();
            try {
                $reader->getCampaignActionConversionsLast30Days('1234567890', $campaignResource, $actionResource);
                $this->fail('Unexpected cross-account evidence query.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('current customer', $e->getMessage());
                $this->assertSame([], $reader->queries);
            }
        }
    }

    public function test_campaign_status_is_read_without_a_traffic_or_date_filter(): void
    {
        $reader = $this->reader([new GoogleAdsRow(['campaign' => new GoogleCampaign(['status' => CampaignStatus::PAUSED])])]);
        $this->assertSame('PAUSED', $reader->getCampaignStatus('1234567890', 'customers/1234567890/campaigns/61'));
        $this->assertStringNotContainsString('metrics', $reader->queries[0]['query']);
        $this->assertStringNotContainsString('segments.date', $reader->queries[0]['query']);
        $this->assertNull($this->reader()->getCampaignStatus('1234567890', 'customers/1234567890/campaigns/61'));
        $this->assertNull($this->reader([new GoogleAdsRow(['campaign' => new GoogleCampaign])])
            ->getCampaignStatus('1234567890', 'customers/1234567890/campaigns/61'));
    }

    public function test_campaign_status_does_not_accept_a_cross_account_resource(): void
    {
        $this->expectException(\LogicException::class);
        $this->reader()->getCampaignStatus('1234567890', 'customers/9876543210/campaigns/61');
    }

    public function test_incomplete_metrics_are_unknown_rather_than_zero(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->reader([new GoogleAdsRow])->getAccountActivityLast30Days('1234567890');
    }
}

class ConversionTrackingReaderFixture extends ConversionTrackingService
{
    public array $queries = [];

    public int $clientChecks = 0;

    public ?\Throwable $failure = null;

    public ?\Throwable $iterationFailure = null;

    public bool $missingClient = false;

    public function __construct(Customer $customer, private array $rows)
    {
        $this->customer = $customer;
    }

    protected function ensureClient(): void
    {
        $this->clientChecks++;
        if ($this->missingClient) {
            throw new \RuntimeException('No Google client');
        }
    }

    protected function searchQuery(string $customerId, string $query): PagedListResponse
    {
        $this->queries[] = ['customer_id' => $customerId, 'query' => $query];
        if ($this->failure) {
            throw $this->failure;
        }

        return new class($this->rows, $this->iterationFailure) extends PagedListResponse
        {
            public function __construct(private array $rows, private ?\Throwable $failure) {}

            public function iterateAllElements(): \Generator
            {
                yield from $this->rows;
                if ($this->failure) {
                    throw $this->failure;
                }
            }
        };
    }
}
