<?php

namespace Tests\Feature;

use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Billing\LaunchFunding;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;
use Tests\TestCase;

class ChannelFundingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_wallet_only_funds_one_approved_allocation_and_settles_resource_days_idempotently(): void
    {
        [$workspace, $campaign, $payer] = $this->workspace();
        $ledger = app(LaunchFunding::class);
        $google = $this->leg($campaign, $payer, 'Google Ads', 10);
        $meta = $this->leg($campaign, $payer, 'Facebook Ads', 8);
        $credit = AdSpendCredit::factory()->for($workspace)->create(['currency' => 'AUD', 'current_balance' => 70]);
        $this->assertTrue($ledger->reserve($google, $this->proof('google'))['ready']);
        $this->assertTrue($ledger->reserve($google, $this->proof('google'))['ready']);
        $this->assertFalse($ledger->reserve($meta, $this->proof('facebook'))['ready']);
        $this->assertSame(1, DB::table('launch_funding_reservations')->where('customer_id', $workspace->id)->count());
        $this->assertTrue($ledger->funded($google));
        $ledger->settle($google->id, '10001', '2026-11-20', 500);
        $ledger->settle($google->id, '10001', '2026-11-20', 500);
        $ledger->settle($google->id, '10001', '2026-11-20', 700);
        $this->assertDatabaseHas('launch_funding_reservations', ['strategy_id' => $google->id, 'held_cents' => 6300]);
        $ledger->release($google);
        $this->assertFalse($ledger->funded($google));
        $this->assertTrue($ledger->reserve($meta, $this->proof('facebook'))['ready']);
        $this->assertSame('70.00', $credit->fresh()->current_balance);
    }

    public function test_approved_shorter_duration_currency_and_exact_account_bound_funding(): void
    {
        [$workspace, $campaign, $payer] = $this->workspace();
        $campaign->update(['start_date' => now()->subWeek(), 'end_date' => now()->addDay()]);
        $google = $this->leg($campaign, $payer, 'Google Ads', 10);
        $credit = AdSpendCredit::factory()->for($workspace)->create(['currency' => 'USD', 'current_balance' => 20]);
        $ledger = app(LaunchFunding::class);
        $this->assertFalse($ledger->reserve($google, $this->proof('google'))['ready']);
        $credit->update(['currency' => 'AUD']);
        $this->assertTrue($ledger->reserve($google, $this->proof('google'))['ready']);
        $this->assertDatabaseHas('launch_funding_reservations', ['strategy_id' => $google->id, 'target_cents' => 2000]);
        $workspace->update(['google_ads_customer_id' => '9098887777']);
        $this->assertFalse($ledger->funded($google->fresh()));
    }

    public function test_out_of_order_refund_and_replayed_success_reconcile_after_payer_membership_changes(): void
    {
        [$workspace, $campaign, $payer] = $this->workspace();
        $strategy = $this->leg($campaign, $payer, 'Google Ads', 10);
        $credit = AdSpendCredit::factory()->for($workspace)->create(['currency' => 'AUD', 'current_balance' => 10]);
        $hold = DB::table('launch_funding_reservations')->insertGetId(['customer_id' => $workspace->id, 'strategy_id' => $strategy->id,
            'launch_id' => $strategy->launch_intent['id'], 'payer_user_id' => $payer->id, 'stripe_customer_id' => 'cus_fixture',
            'platform' => 'google', 'account_id' => '1234567890', 'currency' => 'AUD', 'payer' => 'agency',
            'target_cents' => 7000, 'held_cents' => 1000, 'charge_cents' => 6000, 'status' => 'collecting', 'created_at' => now(), 'updated_at' => now()]);
        $meta = ['type' => 'ad_spend_credit', 'launch_reservation_id' => (string) $hold, 'customer_id' => (string) $workspace->id];
        $ledger = app(LaunchFunding::class);
        $refund = ['id' => 'ch_fixture', 'payment_intent' => 'pi_fixture', 'customer' => 'cus_fixture', 'currency' => 'aud',
            'amount_refunded' => 700, 'metadata' => $meta];
        $ledger->recordRefund($hold, $refund);
        $ledger->recordRefund($hold, $refund);
        $workspace->users()->detach($payer);
        $payment = PaymentIntent::constructFrom(['id' => 'pi_fixture', 'status' => 'succeeded', 'amount' => 6000, 'amount_received' => 6000,
            'customer' => 'cus_fixture', 'currency' => 'aud', 'metadata' => $meta]);
        $ledger->creditPayment($hold, $payment);
        $ledger->creditPayment($hold, $payment);
        $this->assertSame('63.00', $credit->fresh()->current_balance);
        $this->assertFalse($ledger->funded($strategy));
        $this->assertDatabaseHas('launch_funding_reservations', ['id' => $hold, 'refunded_cents' => 700, 'payment_intent_id' => 'pi_fixture']);
    }

    public function test_a_late_provider_spend_revision_reopens_a_closed_day_and_bills_only_the_difference(): void
    {
        [$workspace, $campaign, $payer] = $this->workspace();
        $strategy = $this->leg($campaign, $payer, 'Google Ads', 10);
        $credit = AdSpendCredit::factory()->for($workspace)->create(['currency' => 'AUD', 'current_balance' => 400]);
        $proof = $this->proof('google');
        $this->assertTrue(app(LaunchFunding::class)->reserve($strategy, $proof)['ready']);
        $strategy->forceFill(['google_ads_campaign_id' => 'customers/1234567890/campaigns/9147',
            'deployment_status' => 'deployed', 'launch_intent' => [...$strategy->launch_intent, 'funding' => $proof]])->save();
        $day = now()->subDays(2)->toDateString();
        $campaign->update(['google_ads_campaign_id' => 'customers/1234567890/campaigns/9147']);
        $metric = \App\Models\GoogleAdsPerformanceData::create(['campaign_id' => $campaign->id, 'strategy_id' => $strategy->id, 'google_campaign_id' => '9147', 'google_account_id' => '1234567890', 'currency' => 'AUD',
            'date' => $day, 'cost' => '7.00', 'impressions' => 20, 'clicks' => 2, 'conversions' => 0]);
        DB::table('ad_spend_billing_runs')->insert(['customer_id' => $workspace->id, 'billing_date' => $day, 'spend_date' => $day,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $job = new \App\Jobs\ProcessDailyAdSpendBilling;
        $service = app(\App\Services\AdSpendBillingService::class);
        $job->handle($service);
        $this->assertSame('393.00', $credit->fresh()->current_balance);
        $this->assertDatabaseHas('ad_spend_billing_runs', ['customer_id' => $workspace->id, 'spend_date' => $day, 'status' => 'completed']);
        $metric->update(['cost' => '9.25', 'currency' => 'USD']);
        $job->handle($service);
        $this->assertSame('393.00', $credit->fresh()->current_balance);
        $this->assertFalse($service->processBillingForDate($workspace, $day)['success']);
        $this->assertSame(0.0, (float) app(\App\Services\Billing\BillablePerformance::class)->query($workspace, 'Google Ads')->whereDate('date', $day)->sum('cost'));
        $metric->update(['currency' => 'AUD']);
        $job->handle($service);
        $job->handle($service);
        $this->assertSame('390.75', $credit->fresh()->current_balance);
        $this->assertDatabaseHas('launch_funding_settlements', ['resource_id' => '9147', 'spend_date' => $day, 'observed_cents' => 925, 'consumed_cents' => 925]);
        $this->assertDatabaseHas('launch_funding_reservations', ['strategy_id' => $strategy->id, 'held_cents' => 6075]);
        $this->assertSame(2, $credit->transactions()->whereDate('billed_for', $day)->count());
    }

    public function test_a_grandfathered_google_sibling_is_excluded_from_the_managed_meta_spend_and_reconciliation(): void
    {
        [$workspace, $campaign, $payer] = $this->workspace();
        $workspace->update(['google_ads_link_status' => 'active']);
        $google = $this->leg($campaign, $payer, 'Google Ads', 10);
        $meta = $this->leg($campaign, $payer, 'Facebook Ads', 8);
        $google->forceFill(['google_ads_campaign_id' => 'customers/1234567890/campaigns/107', 'launch_intent' => [...$google->launch_intent, 'funding' => [...$this->proof('google'), 'payer' => 'customer']]])->save();
        $credit = AdSpendCredit::factory()->for($workspace)->create(['currency' => 'AUD', 'current_balance' => 500]);
        $this->assertTrue(app(LaunchFunding::class)->reserve($meta, $this->proof('facebook'))['ready']);
        $meta->forceFill(['facebook_campaign_id' => '876', 'deployment_status' => 'deployed', 'launch_intent' => [...$meta->launch_intent, 'funding' => $this->proof('facebook')]])->save();
        $day = now()->subDay()->toDateString();
        $common = ['campaign_id' => $campaign->id, 'date' => $day, 'impressions' => 10, 'clicks' => 1, 'conversions' => 0];
        \App\Models\GoogleAdsPerformanceData::create([...$common, 'google_campaign_id' => '107', 'strategy_id' => $google->id, 'cost' => 70, 'google_account_id' => '1234567890', 'currency' => 'AUD']);
        DB::table('facebook_ads_performance_data')->insert([...$common, 'facebook_campaign_id' => '876', 'strategy_id' => $meta->id, 'cost' => 9, 'facebook_account_id' => '4567', 'currency' => 'AUD']);
        $reporting = app(\App\Services\Billing\BillablePerformance::class);
        $this->assertSame(0.0, (float) $reporting->query($workspace, 'Google Ads')->sum('cost'));
        $this->assertSame(9.0, (float) $reporting->query($workspace, 'Facebook Ads')->sum('cost'));
        $provider = $this->createMock(\App\Services\FacebookAds\InsightService::class);
        $row = ['account_id' => '4567', 'account_currency' => 'AUD', 'campaign_id' => '876', 'date_start' => $day, 'date_stop' => $day, 'spend' => '9.00'];
        $provider->expects($this->exactly(2))->method('getCampaignInsights')->with('876', $day, $day, ['spend', 'account_id', 'account_currency', 'campaign_id', 'date_start', 'date_stop'])
            ->willReturn([[...$row, 'account_currency' => 'USD']], [$row]);
        $this->app->bind(\App\Services\FacebookAds\InsightService::class, fn () => $provider);
        $billing = app(\App\Services\AdSpendBillingService::class);
        $this->assertFalse($billing->processBillingForDate($workspace, $day)['success']);
        $this->assertSame('500.00', $credit->fresh()->current_balance);
        $this->assertTrue($billing->processBillingForDate($workspace, $day)['success']);
        $this->assertSame('491.00', $credit->fresh()->current_balance);
    }

    public function test_an_automatic_refill_charges_only_the_remaining_approved_shortfall(): void
    {
        [$workspace, $campaign, $payer] = $this->workspace();
        $campaign->update(['status' => \App\Enums\CampaignStatus::Active, 'end_date' => now()->addDay()]);
        $leg = $this->leg($campaign, $payer, 'Google Ads', 10);
        $credit = AdSpendCredit::factory()->for($workspace)->create(['currency' => 'AUD', 'current_balance' => 20]);
        $proof = $this->proof('google');
        $this->assertTrue(app(LaunchFunding::class)->reserve($leg, $proof)['ready']);
        $leg->forceFill(['deployment_status' => 'deployed', 'google_ads_campaign_id' => 'customers/1234567890/campaigns/899',
            'launch_intent' => [...$leg->launch_intent, 'funding' => $proof]])->save();
        $date = now()->subDay()->toDateString();
        \App\Models\GoogleAdsPerformanceData::create(['campaign_id' => $campaign->id, 'strategy_id' => $leg->id, 'google_campaign_id' => '899', 'google_account_id' => '1234567890', 'currency' => 'AUD',
            'date' => $date, 'cost' => '12.00', 'impressions' => 10, 'clicks' => 2, 'conversions' => 0]);
        $billing = $this->getMockBuilder(\App\Services\AdSpendBillingService::class)->onlyMethods(['chargeCustomer'])->getMock();
        $billing->expects($this->once())->method('chargeCustomer')->willReturnCallback(function ($customer, $amount) use ($workspace) {
            $this->assertSame($workspace->id, $customer->id);
            $this->assertSame(12.0, $amount);

            return ['success' => true, 'charge_id' => 'pi_refill_fixture'];
        });
        $this->assertTrue($billing->processBillingForDate($workspace, $date)['success']);
        $this->assertSame('20.00', $credit->fresh()->current_balance);
        $this->assertSame(1, $credit->transactions()->where('stripe_charge_id', 'pi_refill_fixture')->count());
    }

    public function test_billing_recovery_resumes_only_the_individually_recorded_agency_pause(): void
    {
        [$workspace, $campaign, $payer] = $this->workspace();
        $payer->forceFill(['subscription_status' => 'active'])->save();
        $workspace->update(['google_ads_link_status' => 'active']);
        $campaign->update(['status' => \App\Enums\CampaignStatus::Active]);
        $direct = $this->leg($campaign, $payer, 'Google Ads', 10);
        $direct->forceFill(['google_ads_campaign_id' => 'customers/1234567890/campaigns/888',
            'launch_intent' => [...$direct->launch_intent, 'funding' => [...$this->proof('google'), 'payer' => 'customer']]])->save();
        foreach (['444', '555'] as $id) {
            $leg = $this->leg($campaign, $payer, 'Facebook Ads', 4);
            $leg->forceFill(['facebook_campaign_id' => $id, 'deployment_status' => 'deployed',
                'launch_intent' => [...$leg->launch_intent, 'funding' => $this->proof('facebook')]])->save();
        }
        $states = ['444' => 'ACTIVE', '555' => 'PAUSED'];
        $writes = [];
        $service = $this->createMock(\App\Services\FacebookAds\CampaignService::class);
        $service->method('getCampaign')->willReturnCallback(function ($id) use (&$states) {
            return ['id' => $id, 'account_id' => '4567', 'status' => $states[$id]];
        });
        $service->method('updateCampaign')->willReturnCallback(function ($id, $payload) use (&$states, &$writes) {
            $states[$id] = $payload['status'];
            $writes[] = [$id, $payload['status']];

            return true;
        });
        $this->app->bind(\App\Services\FacebookAds\CampaignService::class, fn () => $service);
        $google = $this->createMock(\App\Services\GoogleAds\CommonServices\GetCampaignStatus::class);
        $google->expects($this->never())->method('__invoke');
        $this->app->bind(\App\Services\GoogleAds\CommonServices\GetCampaignStatus::class, fn () => $google);
        $consent = $this->createMock(\App\Services\Deployment\CampaignLaunchIntent::class);
        $consent->method('canPublish')->willReturn(true);
        $this->app->instance(\App\Services\Deployment\CampaignLaunchIntent::class, $consent);
        app(\App\Services\Billing\ChannelSpendControl::class)->apply($campaign, resume: false);
        $this->assertSame(['444' => 'PAUSED', '555' => 'PAUSED'], $states);
        $this->assertArrayHasKey('facebook:4567:444', $campaign->fresh()->search_delivery_state['billing_resource_pauses']);
        $result = app(\App\Services\AdSpendBillingService::class)->recoverCampaigns($workspace, campaignsWerePaused: false);
        $this->assertSame(1, $result['resumed']);
        $this->assertSame([['444', 'PAUSED'], ['444', 'ACTIVE']], $writes);
        $this->assertSame('PAUSED', $states['555']);
    }

    private function workspace(): array
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'facebook_ads_account_id' => 'act_4567', 'currency_code' => 'AUD', 'timezone' => 'UTC']);
        $campaign = Campaign::factory()->for($customer)->create(['daily_budget' => 18, 'approved_daily_budget' => 18, 'budget_confirmed_at' => now()]);
        $payer = User::factory()->create();
        $customer->users()->attach($payer->id, ['role' => 'owner']);

        return [$customer, $campaign, $payer];
    }

    private function leg(Campaign $campaign, User $payer, string $platform, int $daily): Strategy
    {
        return Strategy::factory()->for($campaign)->create(['platform' => $platform, 'signed_off_at' => now(), 'daily_budget' => $daily,
            'deployment_status' => 'awaiting_launch', 'launch_intent' => ['id' => (string) Str::uuid(), 'payer_user_id' => $payer->id,
                'daily_cents' => $daily * 100, 'max_reserve_cents' => $daily * 100 * LaunchFunding::runwayDays($campaign), 'version' => 2]]);
    }

    private function proof(string $channel): array
    {
        return ['ready' => true, 'account' => $channel === 'google' ? '1234567890' : '4567', 'platform' => $channel, 'currency' => 'AUD', 'payer' => 'agency'];
    }
}
