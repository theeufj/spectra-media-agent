<?php

namespace App\Services\GoogleAds;

use App\Models\Customer as CustomerModel;
use Google\Ads\GoogleAds\V22\Enums\TimeTypeEnum\TimeType;
use Google\Ads\GoogleAds\V22\Resources\BillingSetup;
use Google\Ads\GoogleAds\V22\Services\BillingSetupOperation;
use Google\Ads\GoogleAds\V22\Services\ListPaymentsAccountsRequest;
use Google\Ads\GoogleAds\V22\Services\MutateBillingSetupRequest;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;
use Illuminate\Support\Facades\Log;

class BillingSetupService extends BaseGoogleAdsService
{
    public function __construct(CustomerModel $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Set up billing on a sub-account by linking it to the MCC's payments account.
     *
     * @param string $subAccountId The sub-account to enable billing on
     * @return bool True if billing was set up successfully
     */
    public function setupBillingForSubAccount(string $subAccountId): bool
    {
        try {
            $this->ensureClient();

            // First check if billing is already set up
            if ($this->hasBillingSetup($subAccountId)) {
                Log::info('Billing already configured for sub-account', [
                    'sub_account_id' => $subAccountId,
                ]);
                return true;
            }

            // Find the MCC's payments account by querying from the sub-account
            $paymentsAccount = $this->findPaymentsAccount($subAccountId);
            if (!$paymentsAccount) {
                Log::warning('No payments account found - billing setup requires a payments profile on the MCC', [
                    'sub_account_id' => $subAccountId,
                ]);
                return false;
            }

            // Create billing setup linking the sub-account to the payments account
            $billingSetup = new BillingSetup([
                'payments_account' => $paymentsAccount,
                'start_time_type' => TimeType::NOW,
            ]);

            $operation = new BillingSetupOperation([
                'create' => $billingSetup,
            ]);

            $request = MutateBillingSetupRequest::build($subAccountId, $operation);

            $billingSetupServiceClient = $this->client->getBillingSetupServiceClient();
            $response = $billingSetupServiceClient->mutateBillingSetup($request);

            Log::info('Billing setup created for sub-account', [
                'sub_account_id' => $subAccountId,
                'resource_name' => $response->getResult()->getResourceName(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to set up billing for sub-account: ' . $e->getMessage(), [
                'sub_account_id' => $subAccountId,
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Check if a billing setup already exists for the account.
     */
    protected function hasBillingSetup(string $accountId): bool
    {
        try {
            $query = "SELECT billing_setup.id, billing_setup.status FROM billing_setup WHERE billing_setup.status = 'APPROVED'";
            $request = new SearchGoogleAdsRequest([
                'customer_id' => $accountId,
                'query' => $query,
            ]);

            $response = $this->client->getGoogleAdsServiceClient()->search($request);
            foreach ($response as $row) {
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::warning('Could not check billing setup status', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Find a payments account accessible from the sub-account.
     *
     * @return string|null The payments account resource name
     */
    protected function findPaymentsAccount(string $subAccountId): ?string
    {
        try {
            $paySvc = $this->client->getPaymentsAccountServiceClient();
            $request = new ListPaymentsAccountsRequest([
                'customer_id' => $subAccountId,
            ]);
            $response = $paySvc->listPaymentsAccounts($request);

            foreach ($response->getPaymentsAccounts() as $account) {
                Log::info('Found payments account for billing setup', [
                    'resource_name' => $account->getResourceName(),
                    'display_name' => $account->getDisplayName(),
                ]);
                return $account->getResourceName();
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('Could not list payments accounts', [
                'sub_account_id' => $subAccountId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
