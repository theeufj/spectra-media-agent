<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Campaigns\SettleDeployedCampaign;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A one-time setup campaign has to actually be paused on Google, not just in
 * our own column.
 *
 * pauseOnPlatform stripped non-digits from google_ads_campaign_id, and that
 * column holds a resource name:
 *
 *   customers/9654834654/campaigns/24246329924
 *     -> 965483465424246329924        (the customer id glued to the front)
 *     -> customers/9654834654/campaigns/965483465424246329924
 *
 * That campaign does not exist, so every pause failed. The column was set to
 * Paused regardless, which is why it read correctly for an hour, and then
 * MonitorCampaignStatus read ENABLED from Google and faithfully corrected us
 * back to Active. Found by checking a campaign a day after this code had
 * "paused" it and finding it live: Google reported status=ENABLED,
 * serving=SERVING.
 *
 * Every one-time setup campaign has therefore gone live, which is the opposite
 * of what the receipt email, the Create my ads dialog and the deployment screen
 * all promise.
 */
class SetupOnlyPauseTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<array{string, string, string}> */
    private array $calls = [];

    private function settler(): SettleDeployedCampaign
    {
        $record = function (string $customerId, string $resource, string $status): void {
            $this->calls[] = [$customerId, $resource, $status];
        };

        return new class($record) extends SettleDeployedCampaign
        {
            public function __construct(private \Closure $record) {}

            protected function campaignStatusService(Customer $customer): UpdateCampaignStatus
            {
                $record = $this->record;

                return new class($record) extends UpdateCampaignStatus
                {
                    public function __construct(private \Closure $record) {}

                    public function execute(string $customerId, string $resourceName, string $status): array
                    {
                        ($this->record)($customerId, $resourceName, $status);

                        return ['success' => true, 'resource_name' => $resourceName, 'new_status' => $status];
                    }
                };
            }
        };
    }

    /** @return array{Customer, Campaign} */
    private function deployedSetupOnly(string $storedCampaignId): array
    {
        $customer = Customer::factory()->create([
            'service_type' => 'setup_only',
            'setup_fee_paid_at' => now(),
            'google_ads_customer_id' => '965-483-4654',
        ]);
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'google_ads_campaign_id' => $storedCampaignId,
            'status' => CampaignStatus::Draft,
        ]);

        return [$customer, $campaign->fresh('customer')];
    }

    public function test_a_resource_name_is_sent_to_google_as_stored(): void
    {
        [, $campaign] = $this->deployedSetupOnly('customers/9654834654/campaigns/24246329924');

        $this->settler()->settle($campaign);

        $this->assertCount(1, $this->calls);
        [$customerId, $resource, $status] = $this->calls[0];

        $this->assertSame('9654834654', $customerId);
        $this->assertSame('customers/9654834654/campaigns/24246329924', $resource);
        $this->assertSame('PAUSED', $status);
    }

    public function test_a_bare_id_is_still_built_into_a_resource_name(): void
    {
        // Older rows and other platforms' paths store the numeric id alone.
        [, $campaign] = $this->deployedSetupOnly('24246329924');

        $this->settler()->settle($campaign);

        $this->assertSame('customers/9654834654/campaigns/24246329924', $this->calls[0][1]);
    }

    public function test_the_campaign_ends_up_paused_in_our_records_too(): void
    {
        [, $campaign] = $this->deployedSetupOnly('customers/9654834654/campaigns/24246329924');

        $this->settler()->settle($campaign);

        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
    }

    public function test_a_platform_read_cannot_start_a_campaign_before_handover(): void
    {
        [$customer, $campaign] = $this->deployedSetupOnly('customers/9654834654/campaigns/24246329924');
        $campaign->update(['status' => CampaignStatus::Paused]);

        /*
           Before handover the customer has no access to the account, so ENABLED
           can only mean our own pause did not take. Promoting on that reading is
           how a campaign we had "paused" was live again an hour later.
        */
        $this->assertFalse($campaign->fresh('customer')->applyPlatformStatus('ENABLED'));
        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);

        // The platform's own answer is still recorded — refusing to act on it is
        // not the same as refusing to know it.
        $this->assertSame('ENABLED', $campaign->fresh()->platform_status);
    }

    public function test_after_handover_the_customer_starting_it_is_respected(): void
    {
        [$customer, $campaign] = $this->deployedSetupOnly('customers/9654834654/campaigns/24246329924');
        $campaign->update(['status' => CampaignStatus::Paused]);
        $customer->forceFill(['handover_at' => now()])->save();

        // The account is theirs now. ENABLED means they switched it on, and
        // recording that is exactly right.
        $this->assertTrue($campaign->fresh('customer')->applyPlatformStatus('ENABLED'));
        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
    }
}
