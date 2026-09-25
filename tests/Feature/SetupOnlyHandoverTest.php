<?php

namespace Tests\Feature;

use App\Jobs\DeployCampaign;
use App\Mail\HandoverComplete;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\User;
use App\Services\AdSpendBillingService;
use App\Services\DeploymentService;
use App\Services\GoogleAds\CommonServices\InviteCustomerUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The one-time setup ends with the customer owning the account.
 *
 * The whole $999 proposition is that Google Ads is intimidating, so we do the
 * intimidating part — the account, the conversion tracking, the campaign, the
 * ads — leave it paused, and hand it over. They add their own billing and spend
 * what they want to spend. No agents, no subscription, nothing recurring.
 *
 * The handover email said exactly that: "the keys are yours", here is your
 * account ID, go to Billing → Settings. But the account is a sub-account under
 * Spectra's MCC, so it stays ours until a Google login is attached to it, and
 * nothing ever attached one. The customer was handed an account number and no
 * way in.
 *
 * It is also no longer a button an admin has to remember. Every other step of
 * the engagement is automatic, so the last one is too: a setup-only deployment
 * that lands cleanly hands itself over. A partial one does not, because handing
 * over a half-built account closes the engagement in our records while leaving
 * the customer short of what they paid for.
 */
class SetupOnlyHandoverTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<array{string, string}> */
    private array $invitations = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /**
     * @param  array{success: bool, error?: string}  $result
     */
    private function fakeInviter(array $result): void
    {
        $this->invitations = [];

        $record = function (string $customerId, string $email): void {
            $this->invitations[] = [$customerId, $email];
        };

        $this->app->bind(InviteCustomerUser::class, function () use ($result, $record) {
            return new class($result, $record) extends InviteCustomerUser
            {
                /** @param array<string, mixed> $result */
                public function __construct(private array $result, private \Closure $record)
                {
                    // Deliberately not calling parent::__construct — it builds a
                    // Google Ads client, which is the thing being stood in for.
                }

                public function execute(string $customerId, string $email, int $accessRole = 2): array
                {
                    ($this->record)($customerId, $email);

                    return $this->result;
                }

                public function hasInvitationOrAccess(string $customerId, string $email): bool
                {
                    return (bool) ($this->result['existing_access'] ?? false);
                }
            };
        });
    }

    /** @return array{User, Customer} */
    private function setupOnlyCustomer(): array
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $customer = Customer::factory()->create([
            'service_type' => 'setup_only',
            'setup_fee_paid_at' => now(),
            'google_ads_customer_id' => '111-222-3333',
        ]);
        $customer->users()->attach($owner->id, ['role' => 'owner']);

        return [$admin, $customer];
    }

    public function test_handover_invites_the_customer_into_their_own_account(): void
    {
        $this->fakeInviter(['success' => true, 'resource_name' => 'customers/1112223333/invitations/1']);
        [$admin, $customer] = $this->setupOnlyCustomer();

        $this->actingAs($admin)->post(route('admin.customers.handover', $customer));

        // Dashes stripped: the API takes the bare id.
        $this->assertSame([['1112223333', 'owner@example.test']], $this->invitations);
        $this->assertNotNull($customer->fresh()->handover_at);
        Mail::assertSent(HandoverComplete::class, 1);
    }

    public function test_the_email_tells_them_to_accept_the_invitation(): void
    {
        $customer = Customer::factory()->create([
            'service_type' => 'setup_only',
            'google_ads_customer_id' => '111-222-3333',
        ]);

        $rendered = (new HandoverComplete($customer, ['owner@example.test']))->render();

        // Without this the email says "the keys are yours" and then sends them
        // to a screen they cannot reach.
        $this->assertStringContainsString('accept the invitation', strtolower($rendered));
        $this->assertStringContainsString('owner@example.test', $rendered);
    }

    public function test_a_failed_invitation_does_not_record_a_handover(): void
    {
        $this->fakeInviter(['success' => false, 'error' => 'USER_NOT_FOUND']);
        [$admin, $customer] = $this->setupOnlyCustomer();

        $this->actingAs($admin)->post(route('admin.customers.handover', $customer));

        /*
         * A customer who cannot open the account has not been handed anything.
         * Stamping handover_at anyway would close the engagement in our records
         * and leave them locked out of what they paid for, with nothing to
         * chase it.
         */
        $this->assertNull($customer->fresh()->handover_at);
        Mail::assertNotSent(HandoverComplete::class);
    }

    public function test_multi_party_approval_does_not_claim_the_customer_has_the_keys(): void
    {
        $this->fakeInviter(['success' => true, 'resource_name' => null, 'approval_pending' => true]);
        [, $customer] = $this->setupOnlyCustomer();

        $result = app(\App\Services\Customers\HandOverAccount::class)->handOver($customer);

        $this->assertFalse($result['handed_over']);
        $this->assertSame('approval_pending', $result['reason']);
        $this->assertNull($customer->fresh()->handover_at);
        $this->assertDatabaseHas('agent_activities', ['customer_id' => $customer->id, 'action' => 'google_ads_admin_approval_pending']);
        Mail::assertNotSent(HandoverComplete::class);
    }

    public function test_pending_review_waits_without_resending_and_finishes_after_approval(): void
    {
        $this->fakeInviter(['success' => true, 'approval_pending' => true]);
        [, $customer] = $this->setupOnlyCustomer();
        app(\App\Services\Customers\HandOverAccount::class)->handOver($customer);

        $this->fakeInviter(['success' => true, 'existing_access' => false]);
        $waiting = app(\App\Services\Customers\HandOverAccount::class)->handOver($customer);
        $this->assertSame('approval_pending', $waiting['reason']);
        $this->assertSame([], $this->invitations);

        $this->fakeInviter(['success' => true, 'existing_access' => true]);
        $done = app(\App\Services\Customers\HandOverAccount::class)->handOver($customer);
        $this->assertTrue($done['handed_over']);
        $this->assertSame([], $this->invitations);
        Mail::assertSent(HandoverComplete::class, 1);
    }

    public function test_an_already_handed_over_customer_is_not_invited_twice(): void
    {
        $this->fakeInviter(['success' => true]);
        [$admin, $customer] = $this->setupOnlyCustomer();
        $customer->forceFill(['handover_at' => now()->subDay()])->save();

        $this->actingAs($admin)->post(route('admin.customers.handover', $customer));

        $this->assertSame([], $this->invitations);
        Mail::assertNotSent(HandoverComplete::class);
    }

    public function test_handover_still_refuses_a_managed_customer(): void
    {
        $this->fakeInviter(['success' => true]);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));
        $managed = Customer::factory()->create(['service_type' => 'managed']);

        $this->actingAs($admin)->post(route('admin.customers.handover', $managed));

        $this->assertNull($managed->fresh()->handover_at);
        $this->assertSame([], $this->invitations);
    }

    public function test_a_clean_deploy_hands_itself_over(): void
    {
        $this->fakeInviter(['success' => true]);
        [, $customer] = $this->setupOnlyCustomer();

        /*
           Asserted on the service rather than through DeployCampaign, which
           cannot reach its success path in a test: it calls
           DeploymentService::deploy() statically, so the platform call cannot be
           stood in for (see DeployCampaignTest). The job's own contribution —
           the gate that decides whether to call this at all — is covered by the
           partial-deploy test below.
        */
        $result = app(\App\Services\Customers\HandOverAccount::class)->handOver($customer);

        $this->assertTrue($result['handed_over']);
        $this->assertSame(['owner@example.test'], $result['invited']);
        $this->assertNotNull($customer->fresh()->handover_at);
        Mail::assertSent(HandoverComplete::class, 1);
    }

    public function test_a_failed_deploy_does_not_hand_over(): void
    {
        Notification::fake();
        $this->fakeInviter(['success' => true]);
        [, $customer] = $this->setupOnlyCustomer();

        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads',
            // Deploy only considers signed-off strategies; without this the job
            // returns before the platform call and the test proves nothing.
            'signed_off_at' => now(),
        ]);

        $this->partialMock(DeploymentService::class, function ($mock) {
            $mock->shouldReceive('deploy')->andReturn(['success' => false, 'error' => 'API error']);
        });

        (new DeployCampaign($campaign))->handle($this->app->make(AdSpendBillingService::class));

        /*
           Nothing was built, so there is nothing to hand over. The failure mode
           this guards is the worse one: a handover_at stamped on an account with
           no working ads in it, which reads as a completed engagement and is
           never chased.
        */
        $this->assertNull($customer->fresh()->handover_at);
        $this->assertSame([], $this->invitations);
        Mail::assertNotSent(HandoverComplete::class);
    }

    public function test_a_setup_only_customer_is_never_asked_for_prepaid_ad_credit(): void
    {
        [, $customer] = $this->setupOnlyCustomer();

        /*
           They fund the account themselves — that is the whole arrangement.
           Charging them for prepaid ad spend on top of the setup fee bills them
           for money we never spend on their behalf, and the deploy path gates on
           this exact predicate.
        */
        $this->assertTrue($customer->isSelfFundedAds());
    }
}
