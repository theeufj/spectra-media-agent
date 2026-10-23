<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Services\Client\CampaignServiceClient;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SetupOnlyAutomaticActivationTest extends TestCase
{
    use DatabaseTransactions;

    private const CUSTOMER_ID = '1234567890';

    private const CAMPAIGN_RESOURCE = 'customers/1234567890/campaigns/999';

    private function campaign(string $serviceType, string $status, array $attributes = []): Campaign
    {
        $customer = Customer::factory()->create([
            'google_ads_customer_id' => self::CUSTOMER_ID,
            'service_type' => $serviceType,
            'setup_fee_paid_at' => $serviceType === 'setup_only' ? now() : null,
        ]);

        return Campaign::factory()->create($attributes + [
            'customer_id' => $customer->id,
            'google_ads_campaign_id' => self::CAMPAIGN_RESOURCE,
            'status' => $status,
            'platform_status' => 'PAUSED',
            'end_date' => now()->addMonth(),
        ]);
    }

    private function service(Customer $customer, bool $expectMutation): UpdateCampaignStatus
    {
        $mutator = $this->createMock(CampaignServiceClient::class);
        $mutator->expects($expectMutation ? $this->once() : $this->never())
            ->method('mutateCampaigns')
            ->with($this->callback(function (MutateCampaignsRequest $request): bool {
                $this->assertSame(self::CUSTOMER_ID, $request->getCustomerId());
                $this->assertFalse($request->getValidateOnly());
                $this->assertCount(1, $request->getOperations());
                $operation = $request->getOperations()[0];
                $this->assertSame(self::CAMPAIGN_RESOURCE, $operation->getUpdate()->getResourceName());
                $this->assertSame(CampaignStatus::ENABLED, $operation->getUpdate()->getStatus());
                $this->assertSame(['status'], iterator_to_array($operation->getUpdateMask()->getPaths()));

                return true;
            }))
            ->willReturn(new MutateCampaignsResponse);
        $client = $this->createMock(GoogleAdsClient::class);
        $client->expects($expectMutation ? $this->once() : $this->never())
            ->method('getCampaignServiceClient')->willReturn($mutator);

        // Skip credential discovery while exercising the real status service,
        // resource lookup, authorization guard and generated mutate request.
        return new class($customer, $client) extends UpdateCampaignStatus
        {
            public function __construct(Customer $customer, GoogleAdsClient $client)
            {
                $this->customer = $customer;
                $this->client = $client;
            }
        };
    }

    public static function setupStatuses(): array
    {
        return ['stale active lifecycle' => ['active'], 'paused lifecycle' => ['paused']];
    }

    #[DataProvider('setupStatuses')]
    public function test_automatic_enable_of_a_handover_account_is_refused_before_google_mutation(string $status): void
    {
        $campaign = $this->campaign('setup_only', $status);

        $result = $this->service($campaign->customer, false)->enable(self::CUSTOMER_ID, self::CAMPAIGN_RESOURCE);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
        $this->assertSame($status, $campaign->fresh()->status->value);
        $this->assertSame('PAUSED', $campaign->fresh()->platform_status);
    }

    public function test_an_active_managed_campaign_retains_its_automatic_enable_path(): void
    {
        $campaign = $this->campaign('managed', 'active');

        $result = $this->service($campaign->customer, true)->enable(self::CUSTOMER_ID, self::CAMPAIGN_RESOURCE);

        $this->assertTrue($result['success']);
        $this->assertSame('ENABLED', $result['new_status']);
    }

    public function test_an_explicitly_approved_handover_restart_does_not_authorize_later_automatic_calls(): void
    {
        $campaign = $this->campaign('setup_only', 'paused');
        $service = $this->service($campaign->customer, true);

        $approved = $service->enable(self::CUSTOMER_ID, self::CAMPAIGN_RESOURCE, ownerApprovedRestart: true);
        $automatic = $service->enable(self::CUSTOMER_ID, self::CAMPAIGN_RESOURCE);

        $this->assertTrue($approved['success']);
        $this->assertSame('ENABLED', $approved['new_status']);
        $this->assertFalse($automatic['success']);
        $this->assertNotEmpty($automatic['error']);
    }

    public static function restartHolds(): array
    {
        return [
            'spending hold' => [['status' => 'paused', 'spend_safety_hold' => ['reason' => 'manual_pause']]],
            'approved end date passed' => [['status' => 'paused', 'end_date' => '2020-01-01']],
            'unbuilt draft' => [['status' => 'draft']],
            'pending admin deployment' => [['status' => 'pending_admin_deployment']],
            'completed campaign' => [['status' => 'completed']],
            'ended campaign' => [['status' => 'ended']],
        ];
    }

    #[DataProvider('restartHolds')]
    public function test_explicit_restart_does_not_override_existing_holds_or_lifecycle_limits(array $attributes): void
    {
        $campaign = $this->campaign('setup_only', 'paused', $attributes);

        $result = $this->service($campaign->customer, false)
            ->enable(self::CUSTOMER_ID, self::CAMPAIGN_RESOURCE, ownerApprovedRestart: true);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }
}
