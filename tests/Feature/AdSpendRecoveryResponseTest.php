<?php

namespace Tests\Feature;

use App\Models\AdSpendCredit;
use App\Models\Customer;
use App\Models\User;
use App\Services\AdSpendBillingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class AdSpendRecoveryResponseTest extends TestCase
{
    use DatabaseTransactions;

    private ClientInterface $previousStripeClient;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashier.secret' => 'sk_test_recovery_response',
            'services.stripe.secret' => 'sk_test_recovery_response',
        ]);
        $this->previousStripeClient = ApiRequestor::httpClient();
        ApiRequestor::setHttpClient(new RecoveryResponseStripeTransport);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient($this->previousStripeClient);
        parent::tearDown();
    }

    private function account(): array
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_status' => 'active',
            'stripe_id' => 'cus_recovery_response',
            'pm_type' => 'visa',
            'pm_last_four' => '4242',
        ]);
        $customer = Customer::factory()->create();
        $customer->users()->attach($user, ['role' => 'owner']);
        $credit = AdSpendCredit::factory()->paused()->create(['customer_id' => $customer->id]);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);

        return [$customer, $credit];
    }

    public static function recoveryOutcomes(): array
    {
        return [
            'all accepted' => [['resumed' => 2, 'failed' => 0, 'skipped' => 0], ' Campaign resume requests were accepted.'],
            'refused' => [['resumed' => 0, 'failed' => 1, 'skipped' => 0], ' Some campaigns could not be restarted. Review their status before assuming ads are running.'],
            'partial recovery' => [['resumed' => 1, 'failed' => 1, 'skipped' => 1], ' Some campaigns could not be restarted. Review their status before assuming ads are running.'],
            'held for review' => [['resumed' => 0, 'failed' => 0, 'skipped' => 1], ' Some campaigns still need review before further restart attempts.'],
            'no campaigns needed recovery' => [['resumed' => 0, 'failed' => 0, 'skipped' => 0], ''],
        ];
    }

    private function successfulCharge(Customer $customer, array $recovery, string $description): void
    {
        $billing = $this->createMock(AdSpendBillingService::class);
        $billing->expects($this->once())->method('addCredit')
            ->with($this->callback(fn (Customer $candidate) => $candidate->is($customer)), 50.0, $description)
            ->willReturn(['success' => true, 'new_balance' => 400]);
        $billing->expects($this->once())->method('recoverCampaigns')
            ->with($this->callback(fn (Customer $candidate) => $candidate->is($customer)), true)
            ->willReturn($recovery);
        $this->app->instance(AdSpendBillingService::class, $billing);
    }

    #[DataProvider('recoveryOutcomes')]
    public function test_retry_reports_payment_success_and_the_actual_campaign_recovery_result(array $recovery, string $campaignMessage): void
    {
        [$customer, $credit] = $this->account();
        $this->successfulCharge($customer, $recovery, 'Payment recovery');

        $this->postJson(route('billing.ad-spend.retry'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('new_balance', 400)
            ->assertJsonPath('campaign_recovery', $recovery)
            ->assertJsonPath('message', 'Payment successful.'.$campaignMessage);

        $this->assertSame(AdSpendCredit::PAYMENT_CURRENT, $credit->fresh()->payment_status);
    }

    #[DataProvider('recoveryOutcomes')]
    public function test_updating_the_card_and_retrying_preserves_the_campaign_recovery_result(array $recovery, string $campaignMessage): void
    {
        [$customer, $credit] = $this->account();
        $this->successfulCharge($customer, $recovery, 'Payment recovery after payment method update');

        $this->postJson(route('billing.ad-spend.update-payment-method'), [
            'payment_method_id' => 'pm_recovery_response',
            'retry_payment' => true,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('new_balance', 400)
            ->assertJsonPath('campaign_recovery', $recovery)
            ->assertJsonPath('message', 'Payment method updated and payment successful.'.$campaignMessage);

        $this->assertSame(AdSpendCredit::PAYMENT_CURRENT, $credit->fresh()->payment_status);
    }
}

/** A closed transport keeps the real Cashier card-update path off the network. */
class RecoveryResponseStripeTransport implements ClientInterface
{
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = parse_url($absUrl, PHP_URL_PATH);
        if ($path === '/v1/payment_methods/pm_recovery_response' && $method === 'get') {
            return [json_encode([
                'id' => 'pm_recovery_response', 'object' => 'payment_method', 'type' => 'card',
                'customer' => 'cus_recovery_response', 'card' => ['brand' => 'visa', 'last4' => '4242'],
            ]), 200, []];
        }
        if ($path === '/v1/customers/cus_recovery_response' && in_array($method, ['get', 'post'], true)) {
            return [json_encode([
                'id' => 'cus_recovery_response', 'object' => 'customer',
                'invoice_settings' => ['default_payment_method' => $method === 'post' ? 'pm_recovery_response' : 'pm_previous'],
            ]), 200, []];
        }

        throw new \RuntimeException('Unexpected Stripe test request: '.$method.' '.$path);
    }
}
