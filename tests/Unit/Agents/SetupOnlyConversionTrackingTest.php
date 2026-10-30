<?php

namespace Tests\Unit\Agents;

use App\Models\Customer;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\GoogleAdsExecutionAgent;
use App\Services\GoogleAds\ConversionTrackingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SetupOnlyConversionTrackingTest extends TestCase
{
    private function agent(Customer $customer): SetupConversionTrackingProbe
    {
        return new SetupConversionTrackingProbe($customer);
    }

    public static function existingActions(): array
    {
        return ['no goal selected yet' => [null],
            'customer already selected a goal' => ['customers/1234567890/conversionActions/222']];
    }

    #[DataProvider('existingActions')]
    public function test_setup_campaign_creation_never_runs_the_generic_goal_or_tag_pipeline(?string $existingAction): void
    {
        Notification::fake();
        $customer = new Customer(['service_type' => 'setup_only', 'google_ads_customer_id' => '1234567890',
            'conversion_action_id' => $existingAction, 'gtm_account_id' => '101', 'gtm_container_id' => 'GTM-OWNED',
            'gtm_workspace_id' => '3', 'gtm_config' => ['container_path' => 'accounts/101/containers/202',
                'ownership' => ['type' => 'customer']]]);
        $before = $customer->getAttributes();
        $genericTracking = $this->createMock(ConversionTrackingService::class);
        $genericTracking->expects($this->never())->method('isConversionTrackingSetUp');
        $this->app->bind(ConversionTrackingService::class, fn () => $genericTracking);

        $agent = $this->agent($customer);
        $result = $agent->configureGenericTracking();

        $this->assertTrue($result->success);
        $this->assertSame([], $result->platformIds);
        $this->assertSame([], $result->warnings);
        $this->assertSame([], $result->errors);
        $this->assertSame($before, $customer->getAttributes());
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_managed_campaigns_still_check_their_existing_google_conversion_tracking(): void
    {
        $customer = new Customer(['service_type' => 'managed', 'google_ads_customer_id' => '1234567890']);
        $genericTracking = $this->createMock(ConversionTrackingService::class);
        $genericTracking->expects($this->once())->method('isConversionTrackingSetUp')
            ->with('1234567890')->willReturn(true);
        $this->app->bind(ConversionTrackingService::class, fn () => $genericTracking);

        $agent = $this->agent($customer);
        $result = $agent->configureGenericTracking();

        $this->assertTrue($result->success);
        $this->assertSame([], $result->warnings);
        $this->assertSame([], $result->platformIds);
    }
}

class SetupConversionTrackingProbe extends GoogleAdsExecutionAgent
{
    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    public function configureGenericTracking(): ExecutionResult
    {
        $result = ExecutionResult::success();
        $this->setupConversionTracking('1234567890', $result);

        return $result;
    }
}
