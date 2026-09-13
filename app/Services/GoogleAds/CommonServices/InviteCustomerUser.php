<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsException;
use Google\Ads\GoogleAds\V22\Enums\AccessRoleEnum\AccessRole;
use Google\Ads\GoogleAds\V22\Resources\CustomerUserAccessInvitation;
use Google\Ads\GoogleAds\V22\Services\CustomerUserAccessInvitationOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCustomerUserAccessInvitationRequest;
use Google\ApiCore\ApiException;

/**
 * Invite the customer into their own Google Ads account.
 *
 * The one-time setup engagement ends with "the keys are yours": we build the
 * account, the campaigns, the conversion tracking, leave everything paused, and
 * hand it over — they add their own billing and run their own spend, with us
 * out of the middle.
 *
 * Except there were no keys. The account is a sub-account under Spectra's MCC,
 * so it is ours until a Google login is attached to it, and nothing ever
 * attached one. The handover email gave the customer an account ID and told
 * them to go to Billing → Settings, in an account they could not open. The
 * invitation is the step that makes the sentence true.
 *
 * ADMIN, not STANDARD: they are the owner now. Admin is the only role that can
 * add billing, which is the first thing the handover email asks them to do.
 */
class InviteCustomerUser extends BaseGoogleAdsService
{
    /**
     * Refusals that mean the customer already has what we were sending.
     *
     * EMAIL_ADDRESS_ALREADY_HAS_PENDING_INVITATION is the one Google actually
     * returns, and it was the one missing. The other three were guesses at the
     * wire format; this one is verbatim from a live account during an
     * end-to-end run:
     *
     *   "errorCode": { "accessInvitationError":
     *                  "EMAIL_ADDRESS_ALREADY_HAS_PENDING_INVITATION" },
     *   "message": "An invitation has already been sent to this email address."
     *
     * Getting this list wrong is not cosmetic. HandOverAccount refuses to stamp
     * handover_at when any invite reports failure, so a refusal misread as an
     * error reports a finished engagement as blocked — an admin pressing the
     * button after the automatic handover was told the customer could not be
     * invited, while their invitation sat waiting for them.
     */
    public static function isAlreadyInvited(string $message): bool
    {
        foreach ([
            'CUSTOMER_USER_ACCESS_INVITATION_ALREADY_EXISTS',
            'EMAIL_ADDRESS_ALREADY_HAS_PENDING_INVITATION',
            'ALREADY_EXISTS',
            'EMAIL_ADDRESS_ALREADY_HAS_ACCESS',
        ] as $code) {
            if (str_contains($message, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string  $customerId  Google Ads customer ID (no dashes)
     * @param  string  $email  the address Google emails the invitation to
     * @return array{success: bool, resource_name?: string|null, error?: string}
     */
    public function execute(string $customerId, string $email, int $accessRole = AccessRole::ADMIN): array
    {
        $this->ensureClient();

        /*
         * Google offers no dry run for this endpoint —
         * MutateCustomerUserAccessInvitationRequest carries customer_id and
         * operation and nothing else. Every other mutation here sets
         * 'validate_only' => $this->dryRun; the only way to honour the same
         * promise is not to make the call, because a dry run that emails a real
         * invitation to a real customer has changed something.
         */
        if ($this->dryRun) {
            $this->logInfo("Dry run: would invite {$email} to Google Ads account {$customerId}");

            return ['success' => true, 'resource_name' => null];
        }

        try {
            $invitation = new CustomerUserAccessInvitation([
                'email_address' => $email,
                'access_role' => $accessRole,
            ]);

            $operation = new CustomerUserAccessInvitationOperation;
            $operation->setCreate($invitation);

            $response = $this->client->getCustomerUserAccessInvitationServiceClient()
                ->mutateCustomerUserAccessInvitation(
                    new MutateCustomerUserAccessInvitationRequest([
                        'customer_id' => $customerId,
                        'operation' => $operation,
                    ])
                );

            $resourceName = $response->getResult()?->getResourceName();

            $this->logInfo("Invited {$email} to Google Ads account {$customerId} as admin");

            return [
                'success' => true,
                'resource_name' => $resourceName,
            ];
        } catch (GoogleAdsException|ApiException $e) {
            /*
             * The common refusals are worth telling apart, because two of them
             * are fine and one is not:
             *
             *  - already invited, or already has access: the outcome we wanted
             *  - the address has no Google account: real, and the customer must
             *    be told, since the invitation will never arrive
             */
            $message = $e->getMessage();

            /*
               EMAIL_ADDRESS_ALREADY_HAS_PENDING_INVITATION is the one Google
               actually returns, and it was the one not listed here.
               ALREADY_EXISTS and EMAIL_ADDRESS_ALREADY_HAS_ACCESS were both
               guesses at the wire format; the real refusal for "we already
               invited them" carries this code, verified against a live account.

               It matters because of what sits downstream. HandOverAccount
               refuses to stamp handover_at if any invite reports failure, so a
               second run — an admin pressing the button after the automatic
               one, or a retry after a partial deploy — reported the engagement
               as blocked when in fact the customer already had their
               invitation waiting.
            */
            if (self::isAlreadyInvited($message)) {
                $this->logInfo("{$email} already has access to {$customerId} or has been invited");

                return ['success' => true, 'resource_name' => null];
            }

            $this->logError("Failed to invite {$email} to {$customerId}: ".$message);

            return ['success' => false, 'error' => $message];
        }
    }
}
