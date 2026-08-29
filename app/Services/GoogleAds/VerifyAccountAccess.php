<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;
use Google\ApiCore\ApiException;

/**
 * Can the MCC still act on this customer's Google Ads account?
 *
 * The cheapest authoritative probe: one GAQL row against the account. A
 * customer who unlinked us answers with USER_PERMISSION_DENIED — the drift
 * signal VerifyLinkedGoogleAdsAccess watches for.
 *
 * Returns true (accessible), false (access revoked), or null when the
 * failure is anything else — quota, transport, a suspended account — which
 * must never be mistaken for revocation.
 */
class VerifyAccountAccess extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    public function __invoke(): ?bool
    {
        $customerId = preg_replace('/[^0-9]/', '', (string) $this->customer->google_ads_customer_id);

        if ($customerId === '') {
            return null;
        }

        try {
            $this->searchQuery($customerId, 'SELECT customer.id FROM customer LIMIT 1');

            return true;
        } catch (ApiException $e) {
            return self::classifyFailure($e->getMessage());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Only an explicit permission error means the link was revoked. Quota,
     * transport, or account-state failures return null — inconclusive, and
     * VerifyLinkedGoogleAdsAccess treats inconclusive as "leave it alone",
     * because a false churn alarm costs trust in both directions.
     */
    public static function classifyFailure(string $message): ?bool
    {
        foreach (['USER_PERMISSION_DENIED', 'PERMISSION_DENIED', 'NOT_ADS_USER'] as $signal) {
            if (str_contains($message, $signal)) {
                return false;
            }
        }

        return null;
    }
}
