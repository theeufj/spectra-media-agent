<?php

namespace Tests\Feature;

use App\Mail\HandoverComplete;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Customers\HandOverAccount;
use App\Services\Customers\SetupGoogleAdsAccess;
use App\Services\GoogleAds\CommonServices\InviteCustomerUser;
use App\Services\GTM\CustomerTrackingOwnership;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SetupOnlyHandoverTest extends TestCase
{
    use DatabaseTransactions;

    private array $invitations = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function customer(bool $identity = true): Customer
    {
        $user = User::factory()->create(['email' => 'login@example.test']);
        $customer = Customer::factory()->create([
            'service_type' => 'setup_only', 'setup_fee_paid_at' => now(),
            'google_ads_customer_id' => '111-222-3333', 'is_sandbox' => false,
            'setup_handover' => $identity ? ['google_identity' => [
                'email' => 'google@example.test', 'subject' => 'google-identity-1',
                'verified_at' => now()->toIso8601String(), 'verified_by_user_id' => $user->id,
            ]] : [],
        ]);
        $customer->users()->attach($user, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'paused']);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'deployment_status' => 'verified']);

        return $customer;
    }

    private function access(string $status, array $inviteResult = ['success' => true]): void
    {
        $record = function (string $account, string $email): void {
            $this->invitations[] = [$account, $email];
        };
        $this->app->bind(InviteCustomerUser::class, fn () => new class($status, $inviteResult, $record) extends InviteCustomerUser
        {
            public function __construct(private string $status, private array $result, private \Closure $record) {}

            public function accessStatus(string $customerId, string $email): string
            {
                return $this->status;
            }

            public function execute(string $customerId, string $email, int $accessRole = 2): array
            {
                ($this->record)($customerId, $email);

                return $this->result;
            }
        });
    }

    private function tracking(bool $connected): void
    {
        $mock = $this->mock(CustomerTrackingOwnership::class);
        $mock->shouldReceive('verify', 'status')->andReturn(['connected' => $connected]);
    }

    public function test_no_signup_email_is_invited_until_google_identity_is_confirmed(): void
    {
        $customer = $this->customer(false);
        $this->mock(InviteCustomerUser::class)->shouldNotReceive('execute', 'accessStatus');
        $result = app(HandOverAccount::class)->handOver($customer);
        $this->assertSame('identity_required', $result['reason']);
        $this->assertNull($customer->fresh()->handover_at);
        Mail::assertNothingSent();
    }

    public function test_sending_to_the_verified_google_identity_does_not_complete_handover(): void
    {
        $customer = $this->customer();
        $this->access('not_invited');
        $result = app(HandOverAccount::class)->handOver($customer);
        $this->assertSame([['1112223333', 'google@example.test']], $this->invitations);
        $this->assertSame('invitation_pending', $result['reason']);
        $this->assertNull($customer->fresh()->handover_at);
        $this->assertSame('invitation_pending', SetupGoogleAdsAccess::status($customer->fresh())['status']);
        Mail::assertNothingSent();
    }

    public function test_pending_google_review_is_not_resent_or_reported_as_accepted(): void
    {
        $customer = $this->customer();
        $this->access('not_invited', ['success' => true, 'approval_pending' => true]);
        $this->assertSame('approval_pending', app(HandOverAccount::class)->handOver($customer)['reason']);
        $this->assertSame('approval_pending', app(HandOverAccount::class)->handOver($customer->fresh())['reason']);
        $this->assertCount(1, $this->invitations);
        $this->access('invitation_pending');
        $this->assertSame('invitation_pending', app(HandOverAccount::class)->handOver($customer->fresh())['reason']);
        $this->assertNull($customer->fresh()->handover_at);
        Mail::assertNothingSent();
    }

    public function test_accepted_ads_access_without_tracking_control_is_not_complete(): void
    {
        $customer = $this->customer();
        $this->access('accepted');
        $this->tracking(false);
        $this->assertSame('tracking_pending', app(HandOverAccount::class)->handOver($customer)['reason']);
        $this->assertNull($customer->fresh()->handover_at);
        Mail::assertNothingSent();
    }

    public function test_google_permission_rejection_cannot_be_overridden_by_cached_ownership(): void
    {
        $customer = $this->customer();
        $this->access('accepted');
        $this->app->instance(CustomerTrackingOwnership::class, new class extends CustomerTrackingOwnership
        {
            public function __construct() {}

            public function verify(Customer $customer): array
            {
                throw ValidationException::withMessages(['container_id' => 'Access removed.']);
            }
        });
        $this->assertSame('tracking_pending', app(HandOverAccount::class)->handOver($customer)['reason']);
        $this->assertNull($customer->fresh()->handover_at);
    }

    public function test_verified_account_access_and_tracking_control_complete_once_without_enabling_ads(): void
    {
        $customer = $this->customer();
        $this->access('accepted');
        $this->tracking(true);
        $this->assertTrue(app(HandOverAccount::class)->handOver($customer)['handed_over']);
        $this->assertNotNull($customer->fresh()->handover_at);
        $this->assertSame('paused', $customer->campaigns()->first()->status->value);
        $this->assertSame([], $this->invitations);
        $this->assertSame('already_handed_over', app(HandOverAccount::class)->handOver($customer->fresh())['reason']);
        Mail::assertSent(HandoverComplete::class, 1);
        $rendered = (new HandoverComplete($customer, ['google@example.test']))->render();
        $this->assertStringContainsString('not that conversion tracking has passed a website test', $rendered);
        $this->assertStringNotContainsString('conversion tracking are all in place', $rendered);
    }

    public function test_unverified_campaign_prevents_completion_even_with_accepted_access(): void
    {
        $customer = $this->customer();
        $customer->campaigns()->first()->strategies()->update(['deployment_status' => 'deployed']);
        $this->access('accepted');
        $this->mock(CustomerTrackingOwnership::class)->shouldNotReceive('verify');
        $this->assertSame('campaign_pending', app(HandOverAccount::class)->handOver($customer)['reason']);
        $this->assertNull($customer->fresh()->handover_at);
    }

    public function test_old_handover_stamp_and_tag_detection_do_not_prove_access(): void
    {
        $customer = $this->customer();
        $customer->update(['handover_at' => now()->subMonth(), 'gtm_installed' => true]);
        $this->access('invitation_pending');
        $this->assertFalse(app(HandOverAccount::class)->handOver($customer)['handed_over']);
        $journey = app(\App\Services\Onboarding\SetupJourney::class)->forCustomer($customer->fresh());
        $this->assertFalse($journey['steps'][3]['completed']);
        $this->assertFalse(collect($journey['checklist'])->firstWhere('title', 'Google Ads administrator access')['done']);
        Mail::assertNothingSent();
    }

    public function test_changed_account_or_identity_invalidates_old_access_evidence(): void
    {
        $customer = $this->customer();
        $this->access('accepted');
        app(SetupGoogleAdsAccess::class)->reconcile($customer);
        $customer->refresh()->update(['google_ads_customer_id' => '2223334444']);
        $this->assertSame('unverified', SetupGoogleAdsAccess::status($customer)['status']);
        $customer->users()->detach();
        $this->assertSame('identity_required', SetupGoogleAdsAccess::status($customer)['status']);
    }

    public function test_access_check_cannot_read_another_customers_google_account(): void
    {
        $customer = $this->customer();
        $this->mock(InviteCustomerUser::class)->shouldNotReceive('execute', 'accessStatus');
        $this->actingAs(User::factory()->create())->post(route('customers.setup.verify-access', $customer))->assertNotFound();
    }

    public function test_setup_only_remains_self_funded_and_managed_customers_cannot_be_handed_over(): void
    {
        $customer = $this->customer();
        $this->assertTrue($customer->isSelfFundedAds());
        $customer->update(['service_type' => 'managed']);
        $this->assertSame('not_setup_only', app(HandOverAccount::class)->handOver($customer)['reason']);
        Mail::assertNothingSent();
    }
}
