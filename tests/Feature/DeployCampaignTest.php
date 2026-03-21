<?php

namespace Tests\Feature;

use App\Jobs\DeployCampaign;
use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Notifications\DeploymentCompleted;
use App\Notifications\DeploymentFailed;
use App\Services\AdSpendBillingService;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DeployCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Customer $customer;
    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->user->customers()->attach($this->customer->id, ['role' => 'owner']);
        $this->campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);
    }

    public function test_deployment_fails_without_customer(): void
    {
        $campaign = Campaign::factory()->create(['customer_id' => null]);

        $job = new DeployCampaign($campaign);

        $billingService = $this->app->make(AdSpendBillingService::class);

        // Should return early without throwing since there's no customer
        $job->handle($billingService);

        // No crash is the assertion — the job handles missing customer gracefully
        $this->assertTrue(true);
    }

    public function test_deployment_fails_when_payment_issue(): void
    {
        $credit = AdSpendCredit::factory()->paused()->create([
            'customer_id' => $this->customer->id,
        ]);

        Strategy::factory()->create(['campaign_id' => $this->campaign->id]);

        $job = new DeployCampaign($this->campaign);
        $billingService = $this->app->make(AdSpendBillingService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot deploy campaign: Payment issue');

        $job->handle($billingService);
    }

    public function test_deployment_notifies_users_on_complete_failure(): void
    {
        Notification::fake();

        $credit = AdSpendCredit::factory()->create([
            'customer_id' => $this->customer->id,
        ]);

        Strategy::factory()->create([
            'campaign_id' => $this->campaign->id,
            'platform' => 'Google Ads',
        ]);

        // Mock DeploymentService to return failure
        $this->partialMock(DeploymentService::class, function ($mock) {
            $mock->shouldReceive('deploy')->andReturn([
                'success' => false,
                'error' => 'API error',
            ]);
        });

        $job = new DeployCampaign($this->campaign);
        $billingService = $this->app->make(AdSpendBillingService::class);
        $job->handle($billingService);

        Notification::assertSentTo($this->user, DeploymentFailed::class);
    }

    public function test_deployment_notifies_users_on_success(): void
    {
        Notification::fake();

        $credit = AdSpendCredit::factory()->create([
            'customer_id' => $this->customer->id,
        ]);

        Strategy::factory()->create([
            'campaign_id' => $this->campaign->id,
            'platform' => 'Google Ads',
        ]);

        // Mock DeploymentService to return success
        $this->partialMock(DeploymentService::class, function ($mock) {
            $mock->shouldReceive('deploy')->andReturn([
                'success' => true,
            ]);
        });

        $job = new DeployCampaign($this->campaign);
        $billingService = $this->app->make(AdSpendBillingService::class);
        $job->handle($billingService);

        Notification::assertSentTo($this->user, DeploymentCompleted::class);
    }
}
