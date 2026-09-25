<?php

namespace Tests\Feature;

use App\Jobs\InvitePaidSetupCustomer;
use App\Jobs\ProvisionGoogleAdsAccount;
use App\Models\AgentActivity;
use App\Models\Customer;
use App\Models\MccAccount;
use App\Models\User;
use App\Services\GoogleAds\CommonServices\InviteCustomerUser;
use App\Services\GoogleAds\CreateAndLinkManagedAccount;
use App\Services\Onboarding\SetupJourney;
use App\Services\SetupFeeService;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient;
use Google\Ads\GoogleAds\V22\Enums\AccessRoleEnum\AccessRole;
use Google\Ads\GoogleAds\V22\Services\Client\CustomerUserAccessInvitationServiceClient;
use Google\Ads\GoogleAds\V22\Services\MutateCustomerUserAccessInvitationRequest;
use Google\Ads\GoogleAds\V22\Services\MutateCustomerUserAccessInvitationResponse;
use Google\Ads\GoogleAds\V22\Services\MutateCustomerUserAccessInvitationResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class EarlySetupAccountInvitationTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(bool $paid = true, ?string $accountId = '1234567890'): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create([
            'service_type' => $paid ? 'setup_only' : 'managed',
            'setup_fee_paid_at' => $paid ? now() : null,
            'google_ads_customer_id' => $accountId,
            'is_sandbox' => false,
        ]);
        $customer->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $customer];
    }

    private function fakeInviter(array $result, ?string $email = null): void
    {
        $this->app->bind(InviteCustomerUser::class, fn () => new class($result, $email) extends InviteCustomerUser
        {
            public function __construct(private array $result, private ?string $expectedEmail) {}

            public function execute(string $customerId, string $recipient, int $accessRole = AccessRole::ADMIN): array
            {
                \PHPUnit\Framework\Assert::assertSame('1234567890', $customerId);
                \PHPUnit\Framework\Assert::assertSame(AccessRole::ADMIN, $accessRole);
                if ($this->expectedEmail !== null) {
                    \PHPUnit\Framework\Assert::assertSame($this->expectedEmail, $recipient);
                }

                return $this->result;
            }
        });
    }

    public function test_paid_owner_is_invited_to_their_child_account_before_handover(): void
    {
        [$user, $customer] = $this->customer();
        $this->fakeInviter(['success' => true, 'resource_name' => 'customers/1234567890/customerUserAccessInvitations/1'], $user->email);

        (new InvitePaidSetupCustomer($customer))->handle();

        $this->assertNull($customer->fresh()->handover_at);
        $this->assertDatabaseHas('agent_activities', ['customer_id' => $customer->id, 'action' => 'google_ads_admin_invitation_sent']);
        $checklist = collect(app(SetupJourney::class)->forCustomer($customer)['checklist']);
        $this->assertTrue($checklist->firstWhere('title', 'Administrator invitation')['done']);
        $this->assertStringContainsString('has been sent', app(SetupJourney::class)->forCustomer($customer)['steps'][3]['description']);
    }

    public function test_unpaid_and_unprovisioned_customers_are_not_invited(): void
    {
        [, $unpaid] = $this->customer(paid: false);
        [, $unprovisioned] = $this->customer(paid: true, accountId: null);
        InvitePaidSetupCustomer::dispatchIfReady($unpaid);
        InvitePaidSetupCustomer::dispatchIfReady($unprovisioned);
        (new InvitePaidSetupCustomer($unpaid))->handle();
        (new InvitePaidSetupCustomer($unprovisioned))->handle();

        Queue::assertNotPushed(InvitePaidSetupCustomer::class);
        $this->assertDatabaseMissing('agent_activities', ['customer_id' => $unpaid->id, 'action' => 'google_ads_admin_invitation_sent']);
        $this->assertDatabaseMissing('agent_activities', ['customer_id' => $unprovisioned->id, 'action' => 'google_ads_admin_invitation_sent']);
    }

    public function test_failed_google_invitation_does_not_mark_access_as_sent(): void
    {
        [, $customer] = $this->customer();
        $this->fakeInviter(['success' => false, 'error' => 'USER_NOT_FOUND']);

        try {
            (new InvitePaidSetupCustomer($customer))->handle();
            $this->fail('The queue must retry a failed invitation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('USER_NOT_FOUND', $e->getMessage());
        }
        $this->assertDatabaseMissing('agent_activities', ['customer_id' => $customer->id, 'action' => 'google_ads_admin_invitation_sent']);
    }

    public function test_google_mutation_without_an_invitation_resource_requires_another_administrator(): void
    {
        [$user, $customer] = $this->customer();
        $service = new class extends CustomerUserAccessInvitationServiceClient
        {
            public function __construct() {}

            public function mutateCustomerUserAccessInvitation(MutateCustomerUserAccessInvitationRequest $request, array $callOptions = []): MutateCustomerUserAccessInvitationResponse
            {
                return new MutateCustomerUserAccessInvitationResponse([
                    'result' => new MutateCustomerUserAccessInvitationResult(['resource_name' => '']),
                ]);
            }
        };
        $client = new class($service) extends GoogleAdsClient
        {
            public function __construct(private CustomerUserAccessInvitationServiceClient $service) {}

            public function getCustomerUserAccessInvitationServiceClient(): CustomerUserAccessInvitationServiceClient
            {
                return $this->service;
            }
        };
        $inviter = new class($customer, $client) extends InviteCustomerUser
        {
            public function __construct(Customer $customer, GoogleAdsClient $client)
            {
                $this->customer = $customer;
                $this->client = $client;
            }
        };

        $result = $inviter->execute('1234567890', $user->email);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['approval_pending']);
        $this->assertNull($result['resource_name']);
    }

    public function test_pending_google_approval_does_not_claim_the_invitation_was_sent(): void
    {
        [$user, $customer] = $this->customer();
        AgentActivity::record('onboarding', 'google_ads_admin_invitation_sent', 'Old incorrect state', $customer->id, null,
            ['google_ads_customer_id' => '1234567890', 'emails' => [$user->email]]);
        $this->fakeInviter(['success' => true, 'resource_name' => null, 'approval_pending' => true], $user->email);

        (new InvitePaidSetupCustomer($customer))->handle();

        $this->assertDatabaseHas('agent_activities', ['customer_id' => $customer->id, 'action' => 'google_ads_admin_approval_pending']);
        $journey = app(SetupJourney::class)->forCustomer($customer);
        $this->assertFalse(collect($journey['checklist'])->firstWhere('title', 'Administrator invitation')['done']);
        $this->assertStringContainsString('No invitation email', collect($journey['checklist'])->firstWhere('title', 'Administrator invitation')['detail']);
    }

    public function test_payment_immediately_queues_invitation_if_a_child_account_already_exists(): void
    {
        Mail::fake();
        [$user, $customer] = $this->customer(paid: false);

        app(SetupFeeService::class)->recordPayment($customer, $user);

        Queue::assertPushed(InvitePaidSetupCustomer::class, fn ($job) => $job->customer->is($customer));
        Queue::assertNotPushed(ProvisionGoogleAdsAccount::class);
    }

    public function test_new_child_account_queues_an_invitation_as_soon_as_it_is_saved(): void
    {
        [, $customer] = $this->customer(paid: true, accountId: null);
        MccAccount::create(['name' => 'Test MCC', 'google_customer_id' => '1112223333', 'refresh_token' => 'test-token', 'is_active' => true]);
        $creator = Mockery::mock(CreateAndLinkManagedAccount::class);
        $creator->shouldReceive('__invoke')->andReturn(['customer_id' => '1234567890', 'resource_name' => 'customers/1234567890']);
        $this->app->instance(CreateAndLinkManagedAccount::class, $creator);

        (new ProvisionGoogleAdsAccount($customer))->handle();

        $this->assertSame('1234567890', $customer->fresh()->google_ads_customer_id);
        Queue::assertPushed(InvitePaidSetupCustomer::class, fn ($job) => $job->customer->is($customer));
    }

    public function test_paid_setup_provisioning_has_no_delay(): void
    {
        [, $customer] = $this->customer(paid: true, accountId: null);

        ProvisionGoogleAdsAccount::dispatchIfNeeded($customer);

        Queue::assertPushed(ProvisionGoogleAdsAccount::class, fn ($job) => $job->customer->is($customer) && $job->delay === null);
    }
}
