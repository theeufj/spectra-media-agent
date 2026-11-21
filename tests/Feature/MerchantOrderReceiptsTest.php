<?php

namespace Tests\Feature;

use App\Jobs\DeliverCampaignOutcome;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Conversions\CampaignOutcomeEvidence;
use App\Services\Reporting\YesterdayPerformanceSummary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MerchantOrderReceiptsTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(): Campaign
    {
        $workspace = Customer::factory()->create(['website' => 'https://shop.example', 'timezone' => 'Australia/Sydney',
            'facebook_ads_account_id' => 'act_'.fake()->unique()->numerify('2#########'), 'facebook_pixel_id' => fake()->unique()->numerify('3#########'), 'google_ads_customer_id' => fake()->unique()->numerify('1#########')]);

        $file = app(\App\Services\Conversions\AdvertiserOutcomeOrigin::class)->file($workspace, 'https://shop.example');
        $workspace->forceFill(['verified_outcome_origin' => hash('sha256', $file['contents']), 'outcome_origin_verified_at' => now()])->save();

        return Campaign::factory()->for($workspace)->create();
    }

    private function channel(Campaign $campaign, string $platform): Strategy
    {
        $strategy = Strategy::factory()->for($campaign)->create(['platform' => $platform,
            'conversion_goals' => ['primary_goal' => 'Purchase', 'action_resource' => 'customers/'.$campaign->customer->cleanGoogleCustomerId().'/conversionActions/42']]);
        $strategy->forceFill(['conversion_webhook_secret' => 'merchant-'.$strategy->id])->save();

        return $strategy;
    }

    private function submit(Strategy $strategy, array $input)
    {
        $payload = json_encode([...['source_id' => 'event-receipt', 'order_id' => 'order-39', 'goal' => 'purchase', 'mode' => 'live',
            'occurred_at' => now()->subDay()->toIso8601String(), 'source_url' => 'https://shop.example/checkout', 'amount' => '27.45', 'currency' => 'AUD',
            'ad_user_data' => 'denied'], ...$input], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;

        return $this->call('POST', route('outcomes.record', $strategy->uuid), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json', 'HTTP_X_OUTCOME_TIMESTAMP' => $timestamp,
            'HTTP_X_OUTCOME_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$payload, $strategy->conversion_webhook_secret)], $payload);
    }

    public function test_refund_retries_partial_refunds_and_currency_totals_are_independent_of_provider_delivery(): void
    {
        $this->freezeSecond();
        $campaign = $this->campaign();
        $meta = $this->channel($campaign, 'Facebook');
        $google = $this->channel($campaign, 'Google');
        $this->submit($meta, [])->assertAccepted();
        $this->submit($google, ['source_id' => 'google-order-event'])->assertAccepted();
        $this->submit($google, ['amount' => '27.46'])->assertConflict();
        $this->submit($meta, ['source_id' => 'alternative-order', 'order_id' => 'order-usd', 'currency' => 'USD', 'amount' => '12.10'])->assertAccepted();
        Queue::fake();
        $refund = ['goal' => 'refund', 'source_id' => 'refund-1', 'amount' => '8.25', 'occurred_at' => now()->toIso8601String()];
        $id = $this->submit($meta, $refund)->assertAccepted()->json('refund_id');
        $this->submit($google, $refund)->assertAccepted()->assertJsonPath('refund_id', $id);
        $this->submit($google, [...$refund, 'amount' => '8.26'])->assertConflict();
        $this->submit($meta, [...$refund, 'source_id' => 'refund-2', 'amount' => '19.20'])->assertAccepted();
        $this->submit($google, [...$refund, 'source_id' => 'refund-3', 'amount' => '0.01'])->assertUnprocessable();
        $this->submit($meta, [...$refund, 'currency' => 'USD'])->assertUnprocessable();
        $this->submit($google, [...$refund, 'order_id' => 'order-usd', 'currency' => 'USD'])->assertConflict();
        $this->submit($meta, [...$refund, 'source_url' => 'https://other.example/refunds'])->assertUnprocessable();
        $this->submit($meta, [...$refund, 'mode' => 'test'])->assertUnprocessable();
        Queue::assertNotPushed(DeliverCampaignOutcome::class);
        $this->assertSame(0, DB::table('campaign_outcome_events')->where('goal', 'refund')->count());
        $this->assertSame(2, app(CampaignOutcomeEvidence::class)->forStrategy($meta)['verified_events']);
        $reporting = app(YesterdayPerformanceSummary::class);
        $date = now('Australia/Sydney');
        $yesterday = $reporting->forCustomer($campaign->customer, $date->copy()->subDay()->toDateString())['first_party'];
        $today = $reporting->forCustomer($campaign->customer, $date->toDateString())['first_party'];
        $this->assertSame(2, $yesterday['purchases']);
        $this->assertSame(['AUD' => '27.45', 'USD' => '12.10'], $yesterday['value_by_currency']);
        $this->assertSame(0, $today['purchases']);
        $this->assertSame(['orders' => 0, 'refunds' => 2, 'gross_cents' => 0, 'refund_cents' => 2745, 'net_cents' => -2745], $today['orders_by_currency']['AUD']);
        $owner = \App\Models\User::factory()->create(['email_verified_at' => now()]);
        $campaign->customer->users()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner)->withSession(['active_customer_id' => $campaign->customer_id])->get(route('dashboard'))
            ->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->component('Dashboard/Index')
            ->where('merchantOrders.AUD.orders', 1)->where('merchantOrders.AUD.refunds', 2)->where('merchantOrders.AUD.net_cents', 0)
            ->where('merchantOrders.USD.gross_cents', 1210));
    }

    public function test_another_tenant_cannot_refund_the_original_order_and_can_reuse_the_order_number(): void
    {
        $this->freezeSecond();
        $first = $this->channel($this->campaign(), 'Facebook');
        $second = $this->channel($this->campaign(), 'Facebook');
        $this->submit($first, [])->assertAccepted();
        $refund = ['goal' => 'refund', 'source_id' => 'refund-1', 'amount' => '2.10', 'occurred_at' => now()->toIso8601String()];
        $this->submit($second, $refund)->assertUnprocessable();
        $this->submit($second, ['amount' => '15.00'])->assertAccepted();
        $this->submit($second, $refund)->assertAccepted();
        $this->assertDatabaseHas('merchant_order_receipts', ['customer_id' => $first->campaign->customer_id, 'refunded_cents' => 0]);
        $this->assertDatabaseHas('merchant_order_receipts', ['customer_id' => $second->campaign->customer_id, 'refunded_cents' => 210]);
    }
}
