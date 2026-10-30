<?php

namespace Tests\Feature;

use App\Services\GoogleAds\CommonServices\InviteCustomerUser;
use Google\Ads\GoogleAds\V26\Enums\AccessInvitationStatusEnum\AccessInvitationStatus;
use Google\Ads\GoogleAds\V26\Enums\AccessRoleEnum\AccessRole;
use Google\Ads\GoogleAds\V26\Resources\CustomerUserAccess;
use Google\Ads\GoogleAds\V26\Resources\CustomerUserAccessInvitation;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;
use Google\ApiCore\PagedListResponse;
use Tests\TestCase;

class GoogleAdministratorAccessEvidenceTest extends TestCase
{
    private function service(array $users, array $invitations): InviteCustomerUser
    {
        $access = $this->createMock(PagedListResponse::class);
        $access->method('iterateAllElements')->willReturn(new \ArrayIterator($users));
        $pending = $this->createMock(PagedListResponse::class);
        $pending->method('iterateAllElements')->willReturn(new \ArrayIterator($invitations));

        return new class($access, $pending) extends InviteCustomerUser
        {
            public function __construct(private PagedListResponse $access, private PagedListResponse $pending) {}

            protected function ensureClient(): void {}

            protected function searchQuery(string $customerId, string $query): PagedListResponse
            {
                \PHPUnit\Framework\Assert::assertSame('1234567890', $customerId);

                return str_contains($query, 'FROM customer_user_access_invitation') ? $this->pending : $this->access;
            }
        };
    }

    public function test_pending_admin_invitation_is_not_accepted_access(): void
    {
        $row = new GoogleAdsRow(['customer_user_access_invitation' => new CustomerUserAccessInvitation([
            'email_address' => 'owner@example.com', 'access_role' => AccessRole::ADMIN,
            'invitation_status' => AccessInvitationStatus::PENDING,
        ])]);
        $this->assertSame('invitation_pending', $this->service([], [$row])->accessStatus('1234567890', 'owner@example.com'));
    }

    public function test_only_exact_verified_google_identity_with_admin_role_counts(): void
    {
        $row = new GoogleAdsRow(['customer_user_access' => new CustomerUserAccess([
            'email_address' => 'OWNER@example.com', 'access_role' => AccessRole::ADMIN,
        ])]);
        $this->assertSame('accepted', $this->service([$row], [])->accessStatus('1234567890', 'owner@example.com'));
        $this->assertSame('not_invited', $this->service([$row], [])->accessStatus('1234567890', 'owner+alias@example.com'));
        $row->getCustomerUserAccess()->setAccessRole(AccessRole::STANDARD);
        $this->assertSame('not_invited', $this->service([$row], [])->accessStatus('1234567890', 'owner@example.com'));
    }
}
