<?php

namespace Tests\Feature;

use App\Models\AgentActivity;
use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\SetupGoogleAdsAccess;
use App\Services\GoogleAds\CommonServices\InviteCustomerUser;
use Google\Ads\GoogleAds\V26\Enums\AccessRoleEnum\AccessRole;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SetupGoogleAdsAccessRetryTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $customer;

    private array $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $owner = User::factory()->create();
        $this->identity = ['email' => 'google-owner@example.com', 'subject' => 'google-subject',
            'verified_at' => now()->toIso8601String(), 'verified_by_user_id' => $owner->id];
        $this->customer = Customer::factory()->create(['service_type' => 'setup_only',
            'setup_fee_paid_at' => now(), 'google_ads_customer_id' => '1234567890', 'is_sandbox' => false,
            'setup_handover' => ['google_identity' => $this->identity]]);
        $this->customer->users()->attach($owner, ['role' => 'owner']);
    }

    private function storeEvidence(string $status, ?string $requestedAt = null): array
    {
        $evidence = ['status' => $status, 'account_id' => '1234567890',
            'email' => $this->identity['email'], 'subject' => $this->identity['subject'],
            'checked_at' => now()->toIso8601String(), 'requested_at' => $requestedAt ?? now()->toIso8601String()];
        $state = $this->customer->fresh()->setup_handover;
        $state['ads_access'] = $evidence;
        $this->customer->update(['setup_handover' => $state]);

        return $evidence;
    }

    private function fakeInviter(\Closure $read, ?\Closure $invite = null): void
    {
        $this->app->bind(InviteCustomerUser::class, fn () => new class($read, $invite) extends InviteCustomerUser
        {
            public function __construct(private \Closure $read, private ?\Closure $invite) {}

            public function accessStatus(string $customerId, string $email): string
            {
                \PHPUnit\Framework\Assert::assertSame('1234567890', $customerId);
                \PHPUnit\Framework\Assert::assertSame('google-owner@example.com', $email);

                return ($this->read)();
            }

            public function execute(string $customerId, string $email, int $accessRole = AccessRole::ADMIN): array
            {
                \PHPUnit\Framework\Assert::assertNotNull($this->invite, 'This request must not send another Google invitation.');
                \PHPUnit\Framework\Assert::assertSame('1234567890', $customerId);
                \PHPUnit\Framework\Assert::assertSame('google-owner@example.com', $email);
                \PHPUnit\Framework\Assert::assertSame(AccessRole::ADMIN, $accessRole);

                return ($this->invite)();
            }
        });
    }

    private function failRead(): void
    {
        $this->fakeInviter(fn () => throw new \RuntimeException('Google temporarily unavailable'));
        try {
            app(SetupGoogleAdsAccess::class)->reconcile($this->customer, inviteIfMissing: true);
            $this->fail('The queue must retry an unavailable Google read.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Google temporarily unavailable', $e->getMessage());
        }
    }

    public function test_repeated_read_errors_preserve_the_pending_approval_receipt_without_reinviting(): void
    {
        $requestedAt = now()->subDays(3)->toIso8601String();
        $this->storeEvidence('approval_pending', $requestedAt);
        $this->failRead();
        $this->travel(1)->hours();
        $this->failRead();
        $failed = SetupGoogleAdsAccess::status($this->customer->fresh());
        $this->assertSame('error', $failed['status']);
        $this->assertSame(['status' => 'approval_pending', 'requested_at' => $requestedAt], $failed['last_invitation']);

        $this->fakeInviter(fn () => 'not_invited');
        $restored = app(SetupGoogleAdsAccess::class)->reconcile($this->customer, inviteIfMissing: true);
        $this->assertSame('approval_pending', $restored['status']);
        $this->assertSame($requestedAt, $restored['requested_at']);
        $this->assertNull($this->customer->fresh()->handover_at);
        $this->assertDatabaseMissing('agent_activities', ['customer_id' => $this->customer->id,
            'action' => 'google_ads_admin_invitation_sent']);
    }

    public function test_expired_review_receipt_allows_a_new_invitation_instead_of_renewing_it_forever(): void
    {
        $requestedAt = now()->subDays(19)->toIso8601String();
        $this->storeEvidence('approval_pending', $requestedAt);
        $this->failRead();
        $this->travel(2)->days();
        $calls = 0;
        $this->fakeInviter(fn () => 'not_invited', function () use (&$calls) {
            $calls++;

            return ['success' => true, 'approval_pending' => true];
        });
        $access = app(SetupGoogleAdsAccess::class)->reconcile($this->customer, inviteIfMissing: true);
        $this->assertSame(1, $calls);
        $this->assertSame('approval_pending', $access['status']);
        $this->assertSame(now()->toIso8601String(), $access['requested_at']);
        $this->assertNotSame($requestedAt, $access['requested_at']);
    }

    public function test_a_concurrent_invitation_receipt_prevents_another_mutation_after_the_google_read(): void
    {
        $receipt = null;
        $this->fakeInviter(function () use (&$receipt) {
            // Another worker completed its mutation after this worker began
            // reading Google, as can happen when the cache lock expires.
            $receipt = $this->storeEvidence('approval_pending');

            return 'not_invited';
        });
        $actual = app(SetupGoogleAdsAccess::class)->reconcile($this->customer, inviteIfMissing: true);
        $this->assertEquals($receipt, $actual);
        $this->assertEquals($receipt, SetupGoogleAdsAccess::status($this->customer->fresh()));
    }

    public function test_an_old_google_read_cannot_overwrite_newer_access_evidence(): void
    {
        $receipt = null;
        $this->fakeInviter(function () use (&$receipt) {
            $receipt = $this->storeEvidence('accepted');

            return 'invitation_pending';
        });
        $actual = app(SetupGoogleAdsAccess::class)->reconcile($this->customer, inviteIfMissing: true);
        $this->assertEquals($receipt, $actual);
        $this->assertSame('accepted', SetupGoogleAdsAccess::status($this->customer->fresh())['status']);
    }

    public function test_a_failed_old_read_cannot_overwrite_newer_access_evidence(): void
    {
        $this->fakeInviter(function () {
            $this->storeEvidence('accepted');
            throw new \RuntimeException('Old read failed');
        });
        try {
            app(SetupGoogleAdsAccess::class)->reconcile($this->customer, inviteIfMissing: true);
            $this->fail('The failed read must be reported.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Old read failed', $e->getMessage());
        }
        $this->assertSame('accepted', SetupGoogleAdsAccess::status($this->customer->fresh())['status']);
    }

    public function test_identity_changed_during_google_read_is_not_invited_and_new_identity_is_not_overwritten(): void
    {
        $newIdentity = array_replace($this->identity, ['subject' => 'new-subject', 'email' => 'new-owner@example.com']);
        $this->fakeInviter(function () use ($newIdentity) {
            $this->customer->update(['setup_handover' => ['google_identity' => $newIdentity]]);

            return 'not_invited';
        });
        try {
            app(SetupGoogleAdsAccess::class)->reconcile($this->customer, inviteIfMissing: true);
            $this->fail('A changed identity must invalidate this request.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('identity or account changed', $e->getMessage());
        }
        $state = $this->customer->fresh()->setup_handover;
        $this->assertEquals($newIdentity, $state['google_identity']);
        $this->assertArrayNotHasKey('ads_access', $state);
        $this->assertSame(0, AgentActivity::where('customer_id', $this->customer->id)->count());
    }

    public function test_a_read_error_does_not_continue_to_claim_accepted_access(): void
    {
        $this->storeEvidence('accepted');
        $this->failRead();
        $status = SetupGoogleAdsAccess::status($this->customer->fresh());
        $this->assertSame('error', $status['status']);
        $this->assertArrayNotHasKey('last_invitation', $status);
    }
}
