<?php

namespace Tests\Feature;

use App\Jobs\BuildSetupOnlyCampaign;
use App\Jobs\DeployCampaign;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A US$999 customer paid us to build the ads. Something has to build them.
 *
 * DeployCampaign is dispatched from three places and every one is a controller —
 * someone pressing Deploy. It also only deploys strategies carrying
 * signed_off_at, which is set in two places, both also a controller. So the
 * one-time setup delivered a Google Ads account and conversion tracking, and
 * then waited for the customer to review a strategy and press deploy: exactly
 * the intimidating part they had paid to avoid, on an account they had not been
 * invited into yet.
 *
 * Signing off for them is the engagement, not a liberty — and nothing goes live
 * either way, because SettleDeployedCampaign pauses a setup-only campaign as it
 * deploys. This fills the account and leaves the switch to them.
 */
class BuildSetupOnlyCampaignTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{Customer, Campaign} */
    private function readyCustomer(array $customerAttributes = []): array
    {
        $customer = Customer::factory()->create(array_merge([
            'service_type' => 'setup_only',
            'setup_fee_paid_at' => now(),
            'google_ads_customer_id' => '1112223333',
        ], $customerAttributes));

        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'auto_generated_at' => now(),
        ]);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'signed_off_at' => null]);

        return [$customer, $campaign];
    }

    public function test_it_signs_off_and_deploys_once_the_account_exists(): void
    {
        Queue::fake();
        [$customer, $campaign] = $this->readyCustomer();

        (new BuildSetupOnlyCampaign($customer))->handle();

        $this->assertNotNull(
            $campaign->strategies()->first()->signed_off_at,
            'the strategy we wrote for them was never signed off, so DeployCampaign would deploy nothing',
        );
        Queue::assertPushed(DeployCampaign::class);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function notReady(): array
    {
        return [
            'has not paid' => [['setup_fee_paid_at' => null]],
            'has no Google Ads account yet' => [['google_ads_customer_id' => null]],
            'is a managed customer' => [['service_type' => 'managed']],
        ];
    }

    /** @param array<string, mixed> $attributes */
    #[\PHPUnit\Framework\Attributes\DataProvider('notReady')]
    public function test_it_does_not_build_before_the_engagement_is_ready(array $attributes): void
    {
        Queue::fake();
        [$customer] = $this->readyCustomer($attributes);

        BuildSetupOnlyCampaign::dispatchIfReady($customer);

        Queue::assertNotPushed(BuildSetupOnlyCampaign::class);
    }

    public function test_it_refuses_to_deploy_the_same_campaign_twice(): void
    {
        Queue::fake();
        [$customer, $campaign] = $this->readyCustomer();
        $campaign->strategies()->update(['deployed_at' => now()]);

        // Reachable from two places — conversion tracking finishing, and the
        // strategy landing — so it has to be safe to arrive at twice.
        (new BuildSetupOnlyCampaign($customer))->handle();

        Queue::assertNotPushed(DeployCampaign::class);
    }

    public function test_a_campaign_with_no_strategy_yet_waits_rather_than_deploying_nothing(): void
    {
        Queue::fake();
        [$customer, $campaign] = $this->readyCustomer();
        $campaign->strategies()->delete();

        (new BuildSetupOnlyCampaign($customer))->handle();

        Queue::assertNotPushed(DeployCampaign::class);
    }

    public function test_it_ignores_a_campaign_the_customer_built_themselves(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create([
            'service_type' => 'setup_only',
            'setup_fee_paid_at' => now(),
            'google_ads_customer_id' => '1112223333',
        ]);
        // No auto_generated_at: this one is theirs, and signing it off on their
        // behalf would be deploying work they had not finished.
        $manual = Campaign::factory()->create(['customer_id' => $customer->id, 'auto_generated_at' => null]);
        Strategy::factory()->create(['campaign_id' => $manual->id, 'signed_off_at' => null]);

        (new BuildSetupOnlyCampaign($customer))->handle();

        $this->assertNull($manual->strategies()->first()->signed_off_at);
        Queue::assertNotPushed(DeployCampaign::class);
    }
}
