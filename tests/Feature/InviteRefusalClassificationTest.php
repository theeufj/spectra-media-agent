<?php

namespace Tests\Feature;

use App\Services\GoogleAds\CommonServices\InviteCustomerUser;
use PHPUnit\Framework\TestCase;

/**
 * Which of Google's refusals mean "already done" rather than "failed".
 *
 * This matters because of what sits downstream: HandOverAccount refuses to
 * stamp handover_at if any invite reports failure. So a refusal misread as an
 * error reports a completed engagement as blocked, and an admin pressing the
 * button after the automatic handover gets told the customer could not be
 * invited — when in fact their invitation is already waiting for them.
 *
 * The list was three guesses at the wire format. Verified against a live
 * account during an end-to-end run, the code Google actually returns for a
 * second invite is EMAIL_ADDRESS_ALREADY_HAS_PENDING_INVITATION, and that was
 * the one not in the list:
 *
 *   "errorCode": { "accessInvitationError":
 *                  "EMAIL_ADDRESS_ALREADY_HAS_PENDING_INVITATION" },
 *   "message": "An invitation has already been sent to this email address."
 */
class InviteRefusalClassificationTest extends TestCase
{
    /** Static, so the classifier is testable without the Google client. */
    private function isAlreadyDone(string $message): bool
    {
        return InviteCustomerUser::isAlreadyInvited($message);
    }

    public function test_a_pending_invitation_is_not_a_failure(): void
    {
        // Verbatim from a live response.
        $this->assertTrue($this->isAlreadyDone(
            '{"errorCode":{"accessInvitationError":"EMAIL_ADDRESS_ALREADY_HAS_PENDING_INVITATION"},'
            .'"message":"An invitation has already been sent to this email address."}'
        ));
    }

    public function test_existing_access_is_not_a_failure(): void
    {
        $this->assertTrue($this->isAlreadyDone('EMAIL_ADDRESS_ALREADY_HAS_ACCESS'));
        $this->assertTrue($this->isAlreadyDone('CUSTOMER_USER_ACCESS_INVITATION_ALREADY_EXISTS'));
    }

    public function test_a_real_refusal_is_still_a_failure(): void
    {
        // The one that genuinely must stop a handover: the address has no
        // Google account, so the invitation will never arrive and the customer
        // has to be told rather than marked handed over.
        $this->assertFalse($this->isAlreadyDone('EMAIL_ADDRESS_NOT_ALLOWED'));
        $this->assertFalse($this->isAlreadyDone('CUSTOMER_NOT_ENABLED'));
    }
}
