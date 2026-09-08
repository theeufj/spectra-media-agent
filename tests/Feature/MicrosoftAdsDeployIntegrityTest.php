<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Strategy;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\MicrosoftAdsExecutionAgent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

/**
 * `executePlan()` recorded `['success' => true]` for any step that returned
 * without throwing, while the steps themselves report trouble by return value —
 * `['error' => …]`, `['skipped' => …]`, `['status' => 'import_failed']`,
 * `['status' => 'tracking_skipped']`. So a run could skip the Google import,
 * create nothing, and still come back successful: DeploymentService wrote
 * deployment_status='deployed' and DeployCampaign mailed "deployment
 * completed". Nothing retries a success.
 *
 * Also pinned here: a fresh campaign must not be hardcoded Paused. Nothing
 * activates Microsoft — ActivateCampaigns dispatches for google and facebook
 * and `continue`s otherwise.
 */
class MicrosoftAdsDeployIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campaigns.testing_mode_default' => false, 'campaigns.default_status' => 'ENABLED']);
        Setting::where('key', 'campaign_testing_mode')->delete();
    }

    private function context(): ExecutionContext
    {
        $customer = Customer::factory()->create([
            'microsoft_ads_customer_id' => '111',
            'microsoft_ads_account_id' => '222',
        ]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Microsoft Ads']);

        return new ExecutionContext(strategy: $strategy, campaign: $campaign, customer: $customer);
    }

    /**
     * @param  array<string, array<string, mixed>>  $stepResults  action => canned payload
     */
    private function runPlan(array $stepResults, ?ExecutionContext $context = null): ExecutionResult
    {
        $context = $context ?? $this->context();

        $agent = new MicrosoftStubbedExecutionAgent($context->customer);
        $agent->stepResults = $stepResults;

        $steps = array_map(fn ($action) => ['action' => $action, 'params' => []], array_keys($stepResults));

        return $agent->runPlan(new ExecutionPlan(steps: $steps), $context);
    }

    public function test_a_failed_google_import_is_not_a_successful_deploy(): void
    {
        $result = $this->runPlan([
            'import_from_google' => ['status' => 'import_failed', 'google_account_id' => '123'],
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('import_failed', $result->errorMessage());
    }

    public function test_a_skipped_google_import_is_not_a_successful_deploy(): void
    {
        $result = $this->runPlan([
            'import_from_google' => ['status' => 'import_skipped', 'reason' => 'no_google_ads_account'],
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('no_google_ads_account', $result->errorMessage());
    }

    public function test_an_ad_group_step_that_skipped_is_not_a_successful_deploy(): void
    {
        $result = $this->runPlan([
            'create_ad_groups' => ['skipped' => 'No Microsoft campaign ID available'],
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No Microsoft campaign ID available', $result->errorMessage());
    }

    public function test_tracking_that_threw_is_not_a_successful_deploy(): void
    {
        $result = $this->runPlan([
            'create_campaign' => ['status' => 'created', 'microsoft_ads_campaign_id' => '900'],
            'configure_tracking' => ['status' => 'tracking_skipped', 'error' => 'UET tag lookup blew up'],
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('UET tag lookup blew up', $result->errorMessage());
    }

    public function test_a_run_that_recorded_no_identifier_cannot_report_success(): void
    {
        // Every step "passed" and nothing exists on the platform.
        $result = $this->runPlan([
            'configure_tracking' => ['status' => 'tracking_configured', 'uet_tag_id' => '42'],
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No Microsoft Ads identifiers were recorded', $result->errorMessage());
    }

    public function test_a_genuine_deploy_still_succeeds_and_carries_its_ids(): void
    {
        $result = $this->runPlan([
            'create_campaign' => ['status' => 'created', 'microsoft_ads_campaign_id' => '900'],
            'create_ad_groups' => ['ad_group_id' => '901', 'ad_created' => 'yes', 'image_extensions' => ['skipped' => 'no_images']],
            'configure_tracking' => ['status' => 'tracking_configured', 'uet_tag_id' => '42'],
        ]);

        $this->assertTrue($result->success, $result->errorMessage());
        $this->assertSame(['campaign' => '900', 'ad_group' => '901'], $result->platformIds);
    }

    public function test_a_nested_best_effort_skip_does_not_fail_the_deploy(): void
    {
        // Image extensions and keyword additions are enhancements to a campaign
        // that does exist; only top-level trouble means nothing was created.
        $result = $this->runPlan([
            'create_campaign' => ['status' => 'created', 'microsoft_ads_campaign_id' => '900'],
            'create_ad_groups' => [
                'ad_group_id' => '901',
                'keywords_added' => ['skipped' => 'No keywords in strategy'],
                'ad_created' => 'yes',
                'image_extensions' => ['error' => 'upload failed'],
            ],
        ]);

        $this->assertTrue($result->success, $result->errorMessage());
    }

    public function test_extension_creation_handled_elsewhere_is_not_read_as_a_skip(): void
    {
        $result = $this->runPlan([
            'create_campaign' => ['status' => 'created', 'microsoft_ads_campaign_id' => '900'],
            'create_extensions' => [],
        ]);

        $this->assertTrue($result->success, $result->errorMessage());
    }

    public function test_a_fresh_campaign_launches_active_unless_testing_mode_is_on(): void
    {
        $method = new ReflectionMethod(MicrosoftAdsExecutionAgent::class, 'deployStatus');
        $method->setAccessible(true);

        $agent = new MicrosoftAdsExecutionAgent(Customer::factory()->create());

        $this->assertSame('Active', $method->invoke($agent));

        Setting::create(['key' => 'campaign_testing_mode', 'value' => '1']);

        $this->assertSame('Paused', $method->invoke($agent));
    }

    public function test_partial_errors_are_read_off_an_otherwise_normal_response(): void
    {
        $method = new ReflectionMethod(MicrosoftAdsExecutionAgent::class, 'partialErrorMessage');
        $method->setAccessible(true);

        $agent = new MicrosoftAdsExecutionAgent(Customer::factory()->create());

        $this->assertNull($method->invoke($agent, ['AdIds' => ['long' => [5]], 'PartialErrors' => null]));

        // AddAds answers 200 with this rather than a SoapFault when it rejects
        // an ad, which is how headline-less ads were recorded as created.
        $this->assertSame(
            'CampaignServiceAdHeadlineRequired Responsive search ads require headlines',
            $method->invoke($agent, ['PartialErrors' => ['BatchError' => [
                ['Index' => 0, 'Code' => 'CampaignServiceAdHeadlineRequired', 'Message' => 'Responsive search ads require headlines'],
            ]]]),
        );
    }
}

/**
 * Swaps out the four step implementations so the aggregation in executePlan()
 * — the thing that was wrong — can be exercised without a SOAP endpoint.
 */
class MicrosoftStubbedExecutionAgent extends MicrosoftAdsExecutionAgent
{
    /** @var array<string, array<string, mixed>> */
    public array $stepResults = [];

    public function runPlan(ExecutionPlan $plan, ExecutionContext $context): ExecutionResult
    {
        return $this->executePlan($plan, $context);
    }

    protected function executeGoogleImport(array $params): array
    {
        return (array) ($this->stepResults['import_from_google'] ?? []);
    }

    protected function executeCreateCampaign(array $params, ExecutionContext $context): array
    {
        return (array) ($this->stepResults['create_campaign'] ?? []);
    }

    protected function executeCreateAdGroups(ExecutionContext $context): array
    {
        return (array) ($this->stepResults['create_ad_groups'] ?? []);
    }

    protected function executeConfigureTracking(): array
    {
        return (array) ($this->stepResults['configure_tracking'] ?? []);
    }
}
