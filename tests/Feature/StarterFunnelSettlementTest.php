<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StarterFunnelSettlementTest extends TestCase
{
    use DatabaseTransactions;

    private const CURRENT = 'price_current_starter_fixture';

    private const LEGACY = 'price_legacy_starter_fixture';

    private const STAGE = 'paid_subscription';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("SET LOCAL TIME ZONE 'Australia/Sydney'");
        config(['cashier.webhook.secret' => 'whsec_funnel_fixture', 'services.stripe.starter_price_id' => self::CURRENT]);
        Plan::updateOrCreate(['slug' => 'starter'], [
            'name' => 'Starter',
            'price_cents' => 9900,
            'stripe_price_id' => self::CURRENT,
            'is_active' => true,
            'is_free' => false,
            'billing_interval' => 'month',
        ])->forceFill(['legacy_stripe_price_ids' => [self::LEGACY]])->save();
    }

    public function test_signed_starter_settlement_counts_once_and_uses_the_earliest_positive_payment_time(): void
    {
        $journey = $this->trackedBuyer();
        $earliest = now()->subHour()->startOfSecond();
        $later = now()->subMinutes(5)->startOfSecond();

        // Stripe does not guarantee the invoice delivery order.
        $this->postInvoice([
            'id' => 'in_funnel_starter_renewal', 'billing_reason' => 'subscription_cycle',
            'status_transitions' => ['paid_at' => $later->timestamp],
        ], type: 'invoice.payment_succeeded')->assertSuccessful();
        $this->assertPaidAt($journey, $later);

        $this->postInvoice(['status_transitions' => ['paid_at' => $earliest->timestamp]])->assertSuccessful();
        $this->postInvoice(['status_transitions' => ['paid_at' => $earliest->timestamp]], type: 'invoice.payment_succeeded')->assertSuccessful();

        $this->assertPaidAt($journey, $earliest);
        $this->assertDatabaseHas('spectra_conversion_events', [
            'deduplication_key' => 'paid_subscription:sub_funnel_starter',
            'value' => '99.00',
        ]);
    }

    public function test_free_first_invoice_and_nonstarter_payments_do_not_count_but_a_later_positive_cycle_does(): void
    {
        $journey = $this->trackedBuyer();
        foreach ([
            ['amount_paid' => 0],
            ['status' => 'open'],
            ['status_transitions' => ['paid_at' => now()->subDays(4)->timestamp]],
            ['currency' => 'aud'],
            ['billing_reason' => 'subscription_update'],
            ['paid_out_of_band' => true],
            ['lines' => ['data' => [['amount' => 9900, 'price' => ['id' => self::LEGACY]]]]],
            ['lines' => ['data' => [['amount' => 9900, 'price' => ['id' => 'price_fund_ad_credit']]]]],
            ['lines' => ['data' => [
                ['amount' => 9900, 'price' => ['id' => self::CURRENT], 'discount_amounts' => [['amount' => 9900]]],
                ['amount' => 9900, 'price' => ['id' => 'price_fund_ad_credit']],
            ]]],
            ['lines' => ['data' => [
                ['amount' => 9900, 'price' => ['id' => self::CURRENT], 'pretax_credit_amounts' => [['amount' => 9900]]],
                ['amount' => 9900, 'price' => ['id' => 'price_fund_ad_credit']],
            ]]],
            ['lines' => ['data' => [['amount' => 9900, 'price' => ['id' => self::CURRENT], 'proration' => true]]]],
            ['subscription' => null, 'parent' => null],
        ] as $invalid) {
            $this->postInvoice($invalid)->assertSuccessful();
        }
        $this->postInvoice(live: false)->assertSuccessful();
        $this->assertDatabaseMissing('signup_funnel_stages', ['journey_id' => $journey, 'stage' => self::STAGE]);

        $paidAt = now()->subMinute()->startOfSecond();
        $this->postInvoice([
            'billing_reason' => 'subscription_cycle', 'amount_paid' => 4900,
            'lines' => ['has_more' => true, 'data' => [['amount' => 9900, 'pricing' => ['price_details' => ['price' => self::CURRENT]], 'discount_amounts' => [['amount' => 5000]]]]],
            'status_transitions' => ['paid_at' => $paidAt->timestamp],
        ])->assertSuccessful();

        $this->assertPaidAt($journey, $paidAt);
    }

    private function trackedBuyer(): string
    {
        $journey = (string) Str::uuid();
        $signup = now()->subDays(3);
        DB::table('signup_funnel_journeys')->insert(['id' => $journey, 'source' => 'unattributed', 'started_at' => $signup->toIso8601String()]);
        DB::table('signup_funnel_stages')->insert(['journey_id' => $journey, 'stage' => 'signup_completed', 'occurred_at' => $signup->toIso8601String()]);
        User::factory()->create(['stripe_id' => 'cus_funnel_starter', 'tenant_key' => 'sitetospend', 'ad_measurement_consent' => false, 'created_at' => $signup])
            ->forceFill(['signup_funnel_journey_id' => $journey])->save();

        return $journey;
    }

    private function assertPaidAt(string $journey, Carbon $paidAt): void
    {
        $stage = DB::table('signup_funnel_stages')->where('journey_id', $journey)->where('stage', self::STAGE)->sole();
        $this->assertSame($paidAt->timestamp, strtotime($stage->occurred_at));
    }

    private function postInvoice(array $overrides = [], string $type = 'invoice.paid', bool $live = true): TestResponse
    {
        $payload = [
            'type' => $type, 'livemode' => $live,
            'data' => ['object' => array_replace([
                'id' => 'in_funnel_starter', 'customer' => 'cus_funnel_starter', 'number' => 'F-0001',
                'subscription' => 'sub_funnel_starter', 'billing_reason' => 'subscription_create', 'status' => 'paid',
                'amount_paid' => 9900, 'currency' => 'usd',
                'lines' => ['data' => [['amount' => 9900, 'price' => ['id' => self::CURRENT]]]],
                'status_transitions' => ['paid_at' => now()->subMinute()->timestamp],
            ], $overrides)],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_funnel_fixture');

        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }
}
