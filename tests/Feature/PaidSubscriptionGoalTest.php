<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\MccAccount;
use App\Models\Setting;
use App\Services\GoogleAds\ActivatePaidSubscriptionGoal;
use App\Services\GoogleAds\DataManagerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaidSubscriptionGoalTest extends TestCase
{
    use DatabaseTransactions;

    private Campaign $campaign;

    private PaidGoalFixture $service;

    private DataManagerService $dataManager;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['conversions.google_ads_customer_id' => '123']);
        Setting::set('conversion_resource_name.paid_subscription', 'customers/123/conversionActions/789');
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123']);
        $this->campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => '456']);
        $this->service = new PaidGoalFixture($customer);
        $this->dataManager = new DataManagerService(new MccAccount(['google_customer_id' => '987']));
        (new \ReflectionProperty($this->dataManager, 'cachedToken'))->setValue($this->dataManager, 'fixture-token');
    }

    public function test_no_bidding_changes_until_the_new_action_accepts_validation(): void
    {
        Http::fake(['datamanager.googleapis.com/*' => Http::response(['error' => 'Resource not found'], 400)]);
        try {
            $this->service->activate($this->campaign, $this->dataManager);
            $this->fail('New actions must validate before any bidding change.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not ready', $e->getMessage());
        }
        $this->assertSame(0, $this->service->mutations);
        Http::assertSent(fn ($request) => $request['validateOnly'] === true && $request['destinations'][0]['productDestinationId'] === '789');
    }

    public function test_ready_action_is_the_only_primary_and_campaign_goal_and_retry_is_idempotent(): void
    {
        Http::fake(['datamanager.googleapis.com/*' => Http::response(['requestId' => 'validated-fixture'])]);
        $this->service->activate($this->campaign, $this->dataManager);
        $this->service->activate($this->campaign, $this->dataManager);
        $this->assertSame(1, $this->service->mutations);
        $this->assertFalse($this->service->actions[0]['conversionAction']['primaryForGoal']);
        $this->assertTrue($this->service->actions[1]['conversionAction']['primaryForGoal']);
        $this->assertFalse($this->service->goals[0]['campaignConversionGoal']['biddable']);
        $this->assertTrue($this->service->goals[1]['campaignConversionGoal']['biddable']);
        $this->assertDatabaseHas('agent_activities', [
            'campaign_id' => $this->campaign->id, 'action' => 'paid_subscription_goal_activated',
        ]);
    }

    public function test_customer_campaign_cannot_be_switched_to_our_own_subscription_goal(): void
    {
        config(['conversions.google_ads_customer_id' => 'other-account']);
        $this->expectException(\LogicException::class);
        $this->service->activate($this->campaign, $this->dataManager);
    }

    public function test_missing_campaign_purchase_goal_leaves_bidding_unchanged(): void
    {
        Http::fake(['datamanager.googleapis.com/*' => Http::response(['requestId' => 'validated-fixture'])]);
        $this->service->goals = [$this->service->goals[0]];
        try {
            $this->service->activate($this->campaign, $this->dataManager);
            $this->fail('Must not mutate until both the action and campaign goal exist.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('campaign goal yet', $e->getMessage());
        }
        $this->assertSame(0, $this->service->mutations);
    }
}

class PaidGoalFixture extends ActivatePaidSubscriptionGoal
{
    public int $mutations = 0;

    public array $actions = [
        ['conversionAction' => ['resourceName' => 'customers/123/conversionActions/111', 'type' => 'UPLOAD_CLICKS', 'category' => 'SIGNUP', 'origin' => 'WEBSITE', 'primaryForGoal' => true]],
        ['conversionAction' => ['resourceName' => 'customers/123/conversionActions/789', 'type' => 'UPLOAD_CLICKS', 'category' => 'PURCHASE', 'origin' => 'WEBSITE', 'primaryForGoal' => false]],
    ];

    public array $goals = [
        ['campaignConversionGoal' => ['resourceName' => 'customers/123/campaignConversionGoals/456~SIGNUP~WEBSITE', 'category' => 'SIGNUP', 'origin' => 'WEBSITE', 'biddable' => true]],
        ['campaignConversionGoal' => ['resourceName' => 'customers/123/campaignConversionGoals/456~PURCHASE~WEBSITE', 'category' => 'PURCHASE', 'origin' => 'WEBSITE', 'biddable' => false]],
    ];

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    protected function readRows(string $customerId, string $query): array
    {
        if (str_contains($query, 'FROM conversion_action')) {
            return $this->actions;
        }
        if (str_contains($query, 'FROM campaign_conversion_goal')) {
            return $this->goals;
        }
        if (str_contains($query, 'FROM conversion_goal_campaign_config')) {
            return [['conversionGoalCampaignConfig' => []]];
        }

        return [['campaign' => ['status' => 'ENABLED']]];
    }

    protected function applyOperations(string $customerId, array $operations): void
    {
        if (! $operations) {
            return;
        }
        $this->mutations++;
        foreach ($operations as $operation) {
            if ($operation->hasConversionActionOperation()) {
                $update = $operation->getConversionActionOperation()->getUpdate();
                foreach ($this->actions as &$row) {
                    if ($row['conversionAction']['resourceName'] === $update->getResourceName()) {
                        $row['conversionAction']['primaryForGoal'] = $update->getPrimaryForGoal();
                    }
                }
                unset($row);
            } else {
                $update = $operation->getCampaignConversionGoalOperation()->getUpdate();
                foreach ($this->goals as &$row) {
                    if ($row['campaignConversionGoal']['resourceName'] === $update->getResourceName()) {
                        $row['campaignConversionGoal']['biddable'] = $update->getBiddable();
                    }
                }
                unset($row);
            }
        }
    }
}
