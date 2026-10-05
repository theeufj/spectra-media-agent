<?php

namespace Tests\Feature;

use App\Features\AutoHealing;
use App\Jobs\RunSelfHealingChecks;
use App\Models\AgentActivity;
use App\Models\AgentRun;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ExceptionLog;
use App\Models\Setting;
use App\Models\Strategy;
use App\Services\Agents\AgentIssue;
use App\Services\Agents\CampaignDiagnosticsAgent;
use App\Services\Agents\CampaignRemediationAgent;
use App\Services\Agents\FacebookAdRelevanceDiagnosticsAgent;
use App\Services\Agents\FacebookLearningPhaseAgent;
use App\Services\Agents\LinkedInCampaignOptimizationAgent;
use App\Services\Agents\SelfHealingAgent;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\CreateSitelinkAssets;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\CommonServices\VerifyConversionGoals;
use App\Services\GoogleAds\PerformanceMaxServices\HealAssetGroupStrength;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class LegacyConversionGoalSafetyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Feature::flushCache();
        Notification::fake();
        Setting::set('managed_billing_enabled', false, 'boolean');
    }

    public function test_legacy_entry_point_only_inventories_default_and_named_primaries_without_demoting_either(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123-456-7890']);
        $client = $this->createMock(GoogleAdsClient::class);
        foreach (['getConversionActionServiceClient', 'getCustomerConversionGoalServiceClient', 'getGoogleAdsServiceClient'] as $method) {
            $client->expects($this->never())->method($method);
        }
        $service = new LegacyConversionAuditFixture($customer, $client);
        $before = $service->actions;

        $result = $service->verifyAndHeal();

        $this->assertSame('observed', $result['status']);
        $this->assertSame('account', $result['scope']);
        $this->assertSame([], $result['actions']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame($before, $service->actions);
        $this->assertSame(['Default Purchase Conversion', 'Paid Subscription'], array_column($result['primary_actions'], 'name'));
        $this->assertSame('1234567890', $service->readCustomerId);
    }

    public function test_secondary_only_inventory_does_not_claim_the_campaign_has_no_valid_goal(): void
    {
        $service = new LegacyConversionAuditFixture(Customer::factory()->create(['google_ads_customer_id' => '123']));
        foreach ($service->actions as &$action) {
            $action['primaryForGoal'] = false;
        }
        unset($action);
        $result = $service->audit();
        $this->assertSame('observed', $result['status']);
        $this->assertSame([], $result['actions']);
        $this->assertSame('conversion_account_primary_missing', $result['warnings'][0]->code);
        $this->assertStringContainsString('custom goals can select secondary', $result['warnings'][0]->message);
    }

    public function test_missing_account_is_unknown_with_a_typed_issue(): void
    {
        $service = new LegacyConversionAuditFixture(Customer::factory()->create());
        $result = $service->verifyAndHeal();
        $this->assertSame('unknown', $result['status']);
        $this->assertSame('conversion_account_missing', $result['warnings'][0]->code);
        $this->assertNull($service->readCustomerId);
    }

    public function test_failed_account_read_reports_and_rethrows_instead_of_empty_success(): void
    {
        $service = new LegacyConversionAuditFixture(Customer::factory()->create(['google_ads_customer_id' => '123']));
        foreach ([new \RuntimeException('Account audit API unavailable'), new \TypeError('Account audit invalid response')] as $failure) {
            $service->failure = $failure;
            try {
                $service->verifyAndHeal();
                $this->fail('API and programming errors must remain visible to the reporting caller.');
            } catch (\Throwable $e) {
                $this->assertSame($failure, $e);
            }
            $this->assertDatabaseHas('runtime_exceptions', ['message' => $failure->getMessage(), 'type' => get_class($failure)]);
        }
    }

    public function test_four_hour_pass_reconciles_each_campaign_intent_instead_of_account_primary_hygiene(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123']);
        Feature::for($customer)->activate(AutoHealing::class);
        $strategies = [];
        foreach (['Signup', 'Purchase'] as $index => $goal) {
            $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active',
                'primary_status' => 'LEARNING', 'platform_status' => 'ENABLED', 'google_ads_campaign_id' => (string) (90 + $index)]);
            $strategies[] = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Search',
                'signed_off_at' => now(), 'conversion_goals' => ['primary_goal' => $goal],
                'google_ads_campaign_id' => 'customers/123/campaigns/'.(90 + $index), 'deployment_status' => 'deploy_unverified']);
        }
        $service = $this->createMock(ReconcileCampaignConversionGoals::class);
        $service->expects($this->exactly(2))->method('inspect')->willReturnCallback(fn ($strategy) => $this->mismatch($strategy));
        $seen = [];
        $service->expects($this->exactly(2))->method('reconcile')->willReturnCallback(function ($strategy, $resource) use (&$seen) {
            $seen[$strategy->id] = ['category' => ReconcileCampaignConversionGoals::categoryFor($strategy), 'resource' => $resource];

            return ['ready' => true, 'status' => 'ready', 'intent' => ['category' => $seen[$strategy->id]['category']],
                'issues' => [], 'actions' => [['type' => 'campaign_conversion_goals_updated']], 'repairable' => false];
        });
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $service);
        $beforeErrors = ExceptionLog::count();

        $this->runHealingPass();

        $this->assertSame('SIGNUP', $seen[$strategies[0]->id]['category']);
        $this->assertSame('PURCHASE', $seen[$strategies[1]->id]['category']);
        $this->assertSame($strategies[0]->google_ads_campaign_id, $seen[$strategies[0]->id]['resource']);
        $this->assertSame($strategies[1]->google_ads_campaign_id, $seen[$strategies[1]->id]['resource']);
        $run = AgentRun::where('job', 'RunSelfHealingChecks')->latest('id')->firstOrFail();
        $this->assertSame(2, $run->actions_taken);
        $this->assertSame('completed', $run->status);
        $this->assertSame($beforeErrors, ExceptionLog::count());
        $this->assertSame(0, AgentActivity::where('customer_id', $customer->id)->where('action', 'conversion_goal_fixed')->count());
        foreach ($strategies as $strategy) {
            $this->assertSame('active', $strategy->campaign->fresh()->status->value);
        }
    }

    public function test_four_hour_goal_diagnosis_remains_read_only_when_auto_healing_is_disabled(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123']);
        Feature::for($customer)->deactivate(AutoHealing::class);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active',
            'primary_status' => 'LEARNING', 'platform_status' => 'ENABLED', 'google_ads_campaign_id' => '90']);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Search',
            'signed_off_at' => now(), 'conversion_goals' => ['primary_goal' => 'Signup'], 'google_ads_campaign_id' => 'customers/123/campaigns/90']);
        $service = $this->createMock(ReconcileCampaignConversionGoals::class);
        $service->expects($this->once())->method('inspect')->willReturnCallback(fn ($strategy) => $this->mismatch($strategy));
        $service->expects($this->never())->method('reconcile');
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $service);

        $this->runHealingPass();

        $run = AgentRun::where('job', 'RunSelfHealingChecks')->latest('id')->firstOrFail();
        $this->assertSame(0, $run->actions_taken);
        $this->assertSame('attention', $run->status);
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $campaign->id, 'action' => 'unresolved_finding']);
        $this->assertSame('ENABLED', $campaign->fresh()->platform_status);
    }

    private function mismatch(Strategy $strategy): array
    {
        return ['status' => 'needs_review', 'ready' => false, 'intent' => ['category' => ReconcileCampaignConversionGoals::categoryFor($strategy)],
            'actions' => [], 'issues' => [new AgentIssue('conversion_goal_mismatch', 'The campaign goals differ from its reviewed intent.')], 'repairable' => true];
    }

    private function runHealingPass(): void
    {
        $strength = $this->createMock(HealAssetGroupStrength::class);
        $strength->method('heal')->willReturn([]);
        $this->app->bind(HealAssetGroupStrength::class, fn () => $strength);
        $sitelinks = $this->createMock(CreateSitelinkAssets::class);
        $sitelinks->method('heal')->willReturn(0);
        $this->app->bind(CreateSitelinkAssets::class, fn () => $sitelinks);
        $reader = $this->createPartialMock(ReadCampaignConfiguration::class, ['rows']);
        $reader->method('rows')->willReturn([['campaign' => ['status' => 'ENABLED']]]);
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
        $healer = $this->createMock(SelfHealingAgent::class);
        $healer->method('heal')->willReturn(['actions_taken' => [], 'errors' => [], 'warnings' => []]);
        $diagnostics = $this->createMock(CampaignDiagnosticsAgent::class);
        $diagnostics->method('diagnose')->willReturnCallback(fn ($campaign) => (new CampaignDiagnosticsAgent)->checkCampaignConversionGoals($campaign));
        $remediator = new CampaignRemediationAgent($this->createMock(GeminiService::class));

        (new RunSelfHealingChecks)->handle($healer, $diagnostics, $remediator,
            $this->createMock(FacebookLearningPhaseAgent::class), $this->createMock(FacebookAdRelevanceDiagnosticsAgent::class),
            $this->createMock(LinkedInCampaignOptimizationAgent::class));
    }
}

class LegacyConversionAuditFixture extends VerifyConversionGoals
{
    public array $actions = [
        ['resourceName' => 'customers/1234567890/conversionActions/1', 'name' => 'Default Purchase Conversion',
            'status' => 'ENABLED', 'category' => 'PURCHASE', 'origin' => 'WEBSITE', 'primaryForGoal' => true],
        ['resourceName' => 'customers/1234567890/conversionActions/2', 'name' => 'Paid Subscription',
            'status' => 'ENABLED', 'category' => 'PURCHASE', 'origin' => 'WEBSITE', 'primaryForGoal' => true],
    ];

    public ?\Throwable $failure = null;

    public ?string $readCustomerId = null;

    public function __construct(Customer $customer, ?GoogleAdsClient $client = null)
    {
        $this->customer = $customer;
        $this->client = $client;
    }

    protected function readActions(string $customerId): array
    {
        $this->readCustomerId = $customerId;
        if ($this->failure) {
            throw $this->failure;
        }

        return $this->actions;
    }
}
