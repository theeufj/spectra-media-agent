<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Setting;
use App\Models\Strategy;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\LinkedInAdsExecutionAgent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Three bugs met in LinkedIn's executePlan(), and all three ended the same way:
 * DeploymentService wrote deployment_status='deployed' for a campaign that did
 * not exist.
 *
 *  - `$allSucceeded` was `status !== 'failed'`, so every 'skipped' step counted
 *    as a success, and executeCreateCampaign() reported success without
 *    checking that an id had come back.
 *  - The campaign was hardcoded PAUSED, and nothing activates LinkedIn —
 *    ActivateCampaigns only dispatches for google and facebook.
 *  - The set_targeting step's parameters were never read, so
 *    buildTargetingCriteria() was dead code and every B2B campaign went up
 *    untargeted while the agent logged that targeting had been applied.
 *
 * Budgets are asserted here too: LinkedIn's `amount` is a decimal string in the
 * account currency, not minor units.
 */
class LinkedInAdsDeployIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'linkedinads.client_id' => 'test-id',
            'linkedinads.client_secret' => 'test-secret',
            'linkedinads.refresh_token' => 'test-refresh',
            'campaigns.testing_mode_default' => false,
            'campaigns.default_status' => 'ENABLED',
        ]);

        // CampaignStatusHelper reads the DB setting before the config fallback.
        Setting::where('key', 'campaign_testing_mode')->delete();

        // BaseLinkedInAdsService consults the platform kill switch in its
        // constructor; an "off" row would make every call below a silent no-op.
        EnabledPlatform::updateOrCreate(['slug' => 'linkedin'], ['name' => 'LinkedIn', 'is_enabled' => true]);
        Cache::forget('enabled_platform_slugs');
    }

    /**
     * @param  array<string, mixed>  $createResponse  Body LinkedIn answers the campaign POST with
     */
    private function fakeLinkedIn(array|string $createResponse = ['id' => 'urn:li:sponsoredCampaign:99'], int $createStatus = 201): void
    {
        Http::fake([
            'www.linkedin.com/oauth/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'api.linkedin.com/rest/adCampaigns*' => Http::response($createResponse, $createStatus),
            'api.linkedin.com/rest/adCreativesV2*' => Http::response(['id' => 'urn:li:sponsoredCreative:5'], 201),
            'api.linkedin.com/*' => Http::response(['id' => 'insight-tag'], 200),
        ]);
    }

    private function context(): ExecutionContext
    {
        $customer = Customer::factory()->create(['linkedin_ads_account_id' => '508123456']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'name' => 'B2B Lead Gen']);
        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'LinkedIn Ads',
            'daily_budget' => 50,
        ]);

        return new ExecutionContext(
            strategy: $strategy,
            campaign: $campaign,
            customer: $customer,
            availableAssets: ['ad_copies' => 1],
        );
    }

    private function runPlan(ExecutionContext $context, array $steps): ExecutionResult
    {
        $agent = new LinkedInAdsExecutionAgent($context->customer);

        $method = new ReflectionMethod(LinkedInAdsExecutionAgent::class, 'executePlan');
        $method->setAccessible(true);

        return $method->invoke($agent, new ExecutionPlan(steps: $steps), $context);
    }

    private function createAndTargetSteps(): array
    {
        // Deliberately in the order the planner emits them: the targeting step
        // comes *after* the step that has to send it.
        return [
            ['action' => 'create_campaign', 'parameters' => ['daily_budget' => 50, 'objective' => 'LEAD_GENERATION']],
            ['action' => 'set_targeting', 'parameters' => [
                'job_titles' => ['urn:li:title:100'],
                'industries' => ['urn:li:industry:4'],
            ]],
        ];
    }

    public function test_a_campaign_create_that_returns_no_id_is_a_failed_deploy(): void
    {
        // LinkedIn's REST CREATE answers 201 with an empty body and the id in
        // the x-restli-id header, which apiCall() discards — so the service
        // hands back ['success' => true] and nothing else.
        $this->fakeLinkedIn('', 201);

        $context = $this->context();
        $result = $this->runPlan($context, $this->createAndTargetSteps());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('did not return a campaign ID', $result->errorMessage());
        $this->assertNull($context->campaign->fresh()->linkedin_campaign_id);
        $this->assertSame([], $result->platformIds);
    }

    public function test_skipped_steps_do_not_count_as_a_successful_deploy(): void
    {
        $this->fakeLinkedIn();

        $context = $this->context();

        // No campaign was created, so the creative step skips. That used to
        // pass `status !== 'failed'` and report the deploy as complete.
        $result = $this->runPlan($context, [
            ['action' => 'create_creatives', 'parameters' => []],
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No LinkedIn campaign ID', $result->errorMessage());
    }

    public function test_a_plan_that_records_no_campaign_urn_cannot_report_success(): void
    {
        $this->fakeLinkedIn();

        $context = $this->context();

        // Every consumer of a LinkedIn deploy keys off linkedin_campaign_id.
        $result = $this->runPlan($context, [
            ['action' => 'setup_conversion_tracking', 'parameters' => []],
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No LinkedIn campaign ID was recorded', $result->errorMessage());
    }

    public function test_targeting_from_the_plan_is_sent_with_the_campaign(): void
    {
        $this->fakeLinkedIn();

        $context = $this->context();
        $result = $this->runPlan($context, $this->createAndTargetSteps());

        $this->assertTrue($result->success, $result->errorMessage());
        $this->assertSame('urn:li:sponsoredCampaign:99', $context->campaign->fresh()->linkedin_campaign_id);
        $this->assertSame(['campaign' => 'urn:li:sponsoredCampaign:99'], $result->platformIds);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/rest/adCampaigns')) {
                return false;
            }

            $criteria = $request->data()['targetingCriteria']['include']['and'] ?? [];

            return $criteria !== []
                && $criteria[0]['or']['urn:li:adTargetingFacet:titles'] === ['urn:li:title:100']
                && $criteria[1]['or']['urn:li:adTargetingFacet:industries'] === ['urn:li:industry:4'];
        });
    }

    public function test_the_budget_is_sent_in_whole_currency_not_minor_units(): void
    {
        $this->fakeLinkedIn();

        $this->runPlan($this->context(), $this->createAndTargetSteps());

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/rest/adCampaigns')) {
                return false;
            }

            // $50/day. Multiplying by 100 asked LinkedIn for $5,000/day.
            return $request->data()['dailyBudget'] === ['currencyCode' => 'USD', 'amount' => '50.00'];
        });
    }

    public function test_the_campaign_launches_active_when_the_default_status_is_enabled(): void
    {
        $this->fakeLinkedIn();

        $this->runPlan($this->context(), $this->createAndTargetSteps());

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/rest/adCampaigns')
            && $request->data()['status'] === 'ACTIVE');
    }

    public function test_testing_mode_still_forces_the_campaign_paused(): void
    {
        Setting::create(['key' => 'campaign_testing_mode', 'value' => '1']);

        $this->fakeLinkedIn();

        $this->runPlan($this->context(), $this->createAndTargetSteps());

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/rest/adCampaigns')
            && $request->data()['status'] === 'PAUSED');
    }
}
