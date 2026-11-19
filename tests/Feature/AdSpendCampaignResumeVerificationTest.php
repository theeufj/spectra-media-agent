<?php

namespace Tests\Feature;

use App\Mail\AdSpendCampaignsResumed;
use App\Models\AdSpendCredit;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Notification;
use App\Models\User;
use App\Services\AdSpendBillingService;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use App\Services\MicrosoftAds\CampaignService as MicrosoftCampaignService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdSpendCampaignResumeVerificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Exceptions::fake();
        Http::fake([]);
        config([
            'services.facebook.system_user_token' => 'test-system-token',
            'linkedinads.refresh_token' => 'test-refresh-token',
            'linkedinads.client_id' => 'test-client',
            'linkedinads.client_secret' => 'test-secret',
        ]);
        EnabledPlatform::updateOrCreate(['slug' => 'linkedin'], ['name' => 'LinkedIn', 'is_enabled' => true]);
    }

    private function pausedCampaign(Customer $customer, array $attributes): Campaign
    {
        return Campaign::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'status' => 'paused',
            'platform_status' => 'PAUSED',
        ], $attributes));
    }

    private function resume(Customer $customer): array
    {
        return (new \ReflectionMethod(AdSpendBillingService::class, 'resumeAllCampaigns'))
            ->invoke(app(AdSpendBillingService::class), $customer);
    }

    private function assertCampaignStatus(Campaign $campaign, bool $active, string $inactivePlatformStatus = 'PAUSED'): void
    {
        $campaign->refresh();
        $this->assertSame($active ? 'active' : 'paused', $campaign->status->value);
        $this->assertSame($active ? 'ENABLED' : $inactivePlatformStatus, $campaign->platform_status);
    }

    public static function acknowledgements(): array
    {
        return ['accepted' => [true], 'rejected' => [false]];
    }

    #[DataProvider('acknowledgements')]
    public function test_facebook_must_accept_the_resume_before_the_campaign_becomes_active(bool $accepted): void
    {
        $customer = Customer::factory()->create(['facebook_ads_account_id' => 'fb-account']);
        $campaign = $this->pausedCampaign($customer, ['facebook_ads_campaign_id' => 'fb-campaign']);
        Http::fake(['graph.facebook.com/*/fb-campaign' => Http::response(['success' => $accepted])]);

        $this->resume($customer);

        $this->assertCampaignStatus($campaign, $accepted, 'UNKNOWN');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['status'] === 'ACTIVE');
        if (! $accepted) {
            Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'Facebook refused to resume the campaign.');
        }
    }

    #[DataProvider('acknowledgements')]
    public function test_linkedin_must_accept_the_resume_before_the_campaign_becomes_active(bool $accepted): void
    {
        $customer = Customer::factory()->create(['linkedin_ads_account_id' => '123', 'currency_code' => 'AUD']);
        $campaign = $this->pausedCampaign($customer, ['linkedin_campaign_id' => '456']);
        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'test-access-token']),
            'api.linkedin.com/rest/adAccounts/123' => Http::response(['id' => 123, 'status' => 'ACTIVE', 'servingStatuses' => ['RUNNABLE'], 'currency' => 'AUD']),
            'api.linkedin.com/rest/*adCampaigns/456' => Http::response('', $accepted ? 204 : 400),
        ]);

        $this->resume($customer);

        $this->assertCampaignStatus($campaign, $accepted, 'UNKNOWN');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/adCampaigns/456') && $request->hasHeader('Authorization', 'Bearer test-access-token')
            && $request['patch']['$set']['status'] === 'ACTIVE');
        if (! $accepted) {
            Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'LinkedIn refused to resume the campaign.');
        }
    }

    private function microsoftService(Customer $customer, array $responses): BillingResumeMicrosoftService
    {
        $service = new BillingResumeMicrosoftService($customer, $responses);
        $this->app->bind(MicrosoftCampaignService::class, fn () => $service);

        return $service;
    }

    #[DataProvider('acknowledgements')]
    public function test_microsoft_partial_errors_do_not_make_the_campaign_active(bool $accepted): void
    {
        $customer = Customer::factory()->create(['microsoft_ads_account_id' => 'ms-account']);
        $campaign = $this->pausedCampaign($customer, ['microsoft_ads_campaign_id' => 'ms-campaign']);
        $service = $this->microsoftService($customer, ['ms-campaign' => $accepted ? [] : [
            'PartialErrors' => ['BatchError' => [['Code' => 'EditorialError', 'Message' => 'Resume rejected']]],
        ]]);

        $this->resume($customer);

        $this->assertCampaignStatus($campaign, $accepted, 'UNKNOWN');
        $this->assertSame(['ms-campaign'], $service->campaignIds);
        if (! $accepted) {
            Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'Microsoft refused to resume the campaign.');
        }
        Http::assertNothingSent();
    }

    #[DataProvider('acknowledgements')]
    public function test_google_still_requires_an_accepted_owner_approved_restart(bool $accepted): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123-456-7890']);
        $campaign = $this->pausedCampaign($customer, ['google_ads_campaign_id' => '999']);
        $service = $this->createMock(UpdateCampaignStatus::class);
        $service->expects($this->once())->method('enable')->with('1234567890', 'customers/1234567890/campaigns/999', true)
            ->willReturn(['success' => $accepted, 'error' => 'Resume rejected']);
        $this->app->bind(UpdateCampaignStatus::class, fn () => $service);

        $this->resume($customer);

        $this->assertCampaignStatus($campaign, $accepted, 'UNKNOWN');
        if (! $accepted) {
            Exceptions::assertReported(fn (\RuntimeException $e) => str_starts_with($e->getMessage(), 'Google refused to resume'));
        }
        Http::assertNothingSent();
    }

    public static function missingAccounts(): array
    {
        return [
            'Google' => ['google_ads_campaign_id', 'google_ads_customer_id', 'Google'],
            'Facebook' => ['facebook_ads_campaign_id', 'facebook_ads_account_id', 'Facebook'],
            'Microsoft' => ['microsoft_ads_campaign_id', 'microsoft_ads_account_id', 'Microsoft'],
            'LinkedIn' => ['linkedin_campaign_id', 'linkedin_ads_account_id', 'LinkedIn'],
        ];
    }

    #[DataProvider('missingAccounts')]
    public function test_a_missing_account_never_activates_a_campaign(string $campaignColumn, string $accountColumn, string $platform): void
    {
        $customer = Customer::factory()->create([$accountColumn => null]);
        $campaign = $this->pausedCampaign($customer, [$campaignColumn => '999']);

        $this->resume($customer);

        $this->assertCampaignStatus($campaign, false);
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === "{$platform} ad account is missing.");
        Http::assertNothingSent();
    }

    public function test_an_error_is_reported_and_does_not_prevent_the_next_campaign_resuming(): void
    {
        $customer = Customer::factory()->create(['microsoft_ads_account_id' => 'ms-account']);
        $failed = $this->pausedCampaign($customer, ['microsoft_ads_campaign_id' => 'ms-broken']);
        $successful = $this->pausedCampaign($customer, ['microsoft_ads_campaign_id' => 'ms-working']);
        $service = $this->microsoftService($customer, [
            'ms-broken' => new \TypeError('Invalid resume response'),
            'ms-working' => [],
        ]);

        $this->resume($customer);

        $this->assertCampaignStatus($failed, false, 'UNKNOWN');
        $this->assertCampaignStatus($successful, true);
        $this->assertSame(['ms-broken', 'ms-working'], $service->campaignIds);
        Exceptions::assertReported(fn (\TypeError $e) => $e->getMessage() === 'Invalid resume response');
    }

    public function test_all_accounts_are_checked_before_resuming_any_platform_of_a_mixed_campaign(): void
    {
        $customer = Customer::factory()->create([
            'facebook_ads_account_id' => 'fb-account',
            'microsoft_ads_account_id' => null,
        ]);
        $campaign = $this->pausedCampaign($customer, [
            'facebook_ads_campaign_id' => 'fb-campaign',
            'microsoft_ads_campaign_id' => 'ms-campaign',
        ]);

        $summary = $this->resume($customer);

        $this->assertCampaignStatus($campaign, false);
        $this->assertSame(['resumed' => 0, 'failed' => 1, 'skipped' => 0], $summary);
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'Microsoft ad account is missing.');
        Http::assertNothingSent();
    }

    public function test_every_deployed_platform_must_accept_before_a_campaign_becomes_active(): void
    {
        $customer = Customer::factory()->create([
            'facebook_ads_account_id' => 'fb-account',
            'microsoft_ads_account_id' => 'ms-account',
        ]);
        $campaign = $this->pausedCampaign($customer, [
            'facebook_ads_campaign_id' => 'fb-campaign',
            'microsoft_ads_campaign_id' => 'ms-campaign',
        ]);
        Http::fake(['graph.facebook.com/*/fb-campaign' => Http::response(['success' => true])]);
        $this->microsoftService($customer, ['ms-campaign' => null]);

        $summary = $this->resume($customer);

        $this->assertCampaignStatus($campaign, false, 'UNKNOWN');
        $this->assertSame(['resumed' => 0, 'failed' => 1, 'skipped' => 0], $summary);
        $activity = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'campaign_resume_failed')->sole();
        $this->assertSame('failed', $activity->status);
        $this->assertSame(['facebook', 'microsoft'], $activity->details['attempted_platforms']);
        $this->assertSame(['facebook'], $activity->details['accepted_platforms']);
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'Microsoft refused to resume the campaign.');

        Http::fake([]);
        $this->assertSame(['resumed' => 0, 'failed' => 0, 'skipped' => 1], $this->resume($customer));
        Http::assertNothingSent();
    }

    public static function safetyHolds(): array
    {
        return [
            'spend hold' => [['spend_safety_hold' => 'review_required']],
            'bounded trial' => [['spend_guardrails' => ['enabled' => true]]],
        ];
    }

    #[DataProvider('safetyHolds')]
    public function test_payment_recovery_preserves_spend_holds_and_bounded_trials(array $attributes): void
    {
        $customer = Customer::factory()->create(['facebook_ads_account_id' => 'fb-account']);
        $campaign = $this->pausedCampaign($customer, array_merge(['facebook_ads_campaign_id' => 'fb-campaign'], $attributes));

        $summary = $this->resume($customer);

        $this->assertCampaignStatus($campaign, false);
        $this->assertSame(['resumed' => 0, 'failed' => 0, 'skipped' => 1], $summary);
        Http::assertNothingSent();
        Exceptions::assertNothingReported();
    }

    public function test_recovery_keeps_the_budget_restoration_step_after_a_resume_is_refused(): void
    {
        $customer = Customer::factory()->create(['facebook_ads_account_id' => 'fb-account']);
        $campaign = $this->pausedCampaign($customer, ['facebook_ads_campaign_id' => 'fb-campaign']);
        Http::fake(['graph.facebook.com/*/fb-campaign' => Http::response(['success' => false])]);
        $service = new class extends AdSpendBillingService
        {
            public array $budgetRestorations = [];

            protected function reduceCampaignBudgets(Customer $customer, float $multiplier): void
            {
                $this->budgetRestorations[] = [$customer->id, $multiplier];
            }
        };

        $summary = $service->recoverCampaigns($customer);

        $this->assertCampaignStatus($campaign, false, 'UNKNOWN');
        $this->assertSame(['resumed' => 0, 'failed' => 1, 'skipped' => 0], $summary);
        $this->assertSame([[$customer->id, 1.0]], $service->budgetRestorations);
    }

    public static function paymentRecoveryOutcomes(): array
    {
        return [
            'rejected resume' => [false, false],
            'retained hold' => [true, true],
            'accepted resume' => [true, false],
        ];
    }

    #[DataProvider('paymentRecoveryOutcomes')]
    public function test_payment_recovery_only_claims_campaigns_resumed_when_every_restart_was_accepted(bool $accepted, bool $held): void
    {
        Mail::fake();
        $customer = Customer::factory()->create(['facebook_ads_account_id' => 'fb-account']);
        $user = User::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);
        $credit = AdSpendCredit::create([
            'customer_id' => $customer->id,
            'current_balance' => 0,
            'initial_credit_amount' => 350,
            'payment_status' => AdSpendCredit::PAYMENT_PAUSED,
            'campaigns_paused_at' => now(),
        ]);
        $this->pausedCampaign($customer, [
            'facebook_ads_campaign_id' => 'fb-campaign',
            'spend_safety_hold' => $held ? 'review_required' : null,
        ]);
        Http::fake(['graph.facebook.com/*/fb-campaign' => Http::response(['success' => $accepted])]);
        $service = new class extends AdSpendBillingService
        {
            protected function chargeCustomer(Customer $customer, float $amount, string $description, string $idempotencyKey): array
            {
                return ['success' => true, 'charge_id' => 'pi_test_recovery'];
            }

            protected function reduceCampaignBudgets(Customer $customer, float $multiplier): void {}

            public function recoverPayment(Customer $customer, AdSpendCredit $credit): array
            {
                return $this->attemptPaymentRecovery($customer, $credit);
            }
        };

        $result = $service->recoverPayment($customer, $credit);

        $this->assertTrue($result['success']);
        $this->assertSame(AdSpendCredit::PAYMENT_CURRENT, $credit->fresh()->payment_status);
        $notification = Notification::where('user_id', $user->id)->sole();
        if ($accepted && ! $held) {
            $this->assertSame(['resumed' => 1, 'failed' => 0, 'skipped' => 0], $result['campaign_recovery']);
            $this->assertSame('Your ads are running again', $notification->title);
            Mail::assertQueued(AdSpendCampaignsResumed::class);
        } else {
            $this->assertSame(['resumed' => 0, 'failed' => $held ? 0 : 1, 'skipped' => $held ? 1 : 0], $result['campaign_recovery']);
            $this->assertSame('Payment received — your campaigns need review', $notification->title);
            $this->assertStringNotContainsString('running again', $notification->message);
            $this->assertSame(Notification::TYPE_BILLING_WARNING, $notification->type);
            Mail::assertNotQueued(AdSpendCampaignsResumed::class);
        }
    }
}

/** Exercises the real PartialErrors handling without opening a SOAP connection. */
class BillingResumeMicrosoftService extends MicrosoftCampaignService
{
    public array $campaignIds = [];

    public function __construct(Customer $customer, private array $responses)
    {
        $this->customer = $customer;
    }

    protected function apiCall(string $operation, array $body): ?array
    {
        $campaignId = $body['Campaigns']['Campaign'][0]['Id'];
        $this->campaignIds[] = $campaignId;
        $response = $this->responses[$campaignId];
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
