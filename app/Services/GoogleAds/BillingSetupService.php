<?php

namespace App\Services\GoogleAds;

use App\Models\Customer as CustomerModel;
use Google\Ads\GoogleAds\V26\Enums\TimeTypeEnum\TimeType;
use Google\Ads\GoogleAds\V26\Resources\BillingSetup;
use Google\Ads\GoogleAds\V26\Services\BillingSetupOperation;
use Google\Ads\GoogleAds\V26\Services\ListPaymentsAccountsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateBillingSetupRequest;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsRequest;
use Illuminate\Support\Facades\Log;

class BillingSetupService extends BaseGoogleAdsService
{
    public function __construct(CustomerModel $customer)
    {
        parent::__construct($customer);
    }

    /** Confirm the serving account and currently effective invoice profile using the MCC credential. */
    public function payerEvidence(string $account): ?array
    {
        $this->ensureClient();
        $accounts = $this->searchQuery($account, 'SELECT customer.id, customer.currency_code, customer.status, customer.time_zone, customer.manager, customer.test_account FROM customer');
        foreach ($accounts->iterateAllElements() as $row) {
            $customer = $row->getCustomer();
            if ((string) $customer->getId() !== $account || $customer->getManager() || $customer->getTestAccount()
                || $customer->getStatus() !== \Google\Ads\GoogleAds\V26\Enums\CustomerStatusEnum\CustomerStatus::ENABLED) {
                return null;
            }
            $currency = $customer->getCurrencyCode();
            $zone = $customer->getTimeZone();
            if (! $zone || ! in_array($zone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
                return null;
            }
            $now = now($zone)->format('Y-m-d H:i:s');
            $profiles = [];
            $setups = $this->searchQuery($account, "SELECT billing_setup.resource_name, billing_setup.payments_account_info.payments_account_id, billing_setup.start_date_time, billing_setup.end_date_time FROM billing_setup WHERE billing_setup.status = 'APPROVED'");
            foreach ($setups->iterateAllElements() as $setupRow) {
                $setup = $setupRow->getBillingSetup();
                if ($setup->getStartDateTime() > $now || ($setup->getEndDateTime() && $setup->getEndDateTime() <= $now)) {
                    continue;
                }
                $source = str_replace('-', '', (string) $setup->getPaymentsAccountInfo()?->getPaymentsAccountId());
                if (ctype_digit($source)) {
                    $profiles[$setup->getResourceName()] = $source;
                }
            }
            if (count($profiles) !== 1) {
                return null;
            }
            $budgets = $this->searchQuery($account, "SELECT account_budget.billing_setup, account_budget.approved_start_date_time, account_budget.approved_end_date_time, account_budget.adjusted_spending_limit_micros, account_budget.adjusted_spending_limit_type, account_budget.amount_served_micros FROM account_budget WHERE account_budget.status = 'APPROVED'");
            foreach ($budgets->iterateAllElements() as $budgetRow) {
                $budget = $budgetRow->getAccountBudget();
                if (isset($profiles[$budget->getBillingSetup()]) && $budget->getApprovedStartDateTime() <= $now
                    && (! $budget->getApprovedEndDateTime() || $budget->getApprovedEndDateTime() > $now)
                    && ($budget->getAdjustedSpendingLimitType() === \Google\Ads\GoogleAds\V26\Enums\SpendingLimitTypeEnum\SpendingLimitType::INFINITE
                        || $budget->getAdjustedSpendingLimitMicros() > $budget->getAmountServedMicros())) {
                    // Reading a billing profile alone does not establish campaign write access.
                    // Validate a disposable budget request without ever creating a budget.
                    $probe = app(CreateCampaignBudget::class, ['customer' => $this->customer]);
                    (clone $probe)->dryRun()->__invoke($account, 'Site to Spend account qualification '.\Illuminate\Support\Str::uuid(), 100000000, false);

                    return ['currency' => $currency, 'source' => $profiles[$budget->getBillingSetup()]];
                }
            }
        }

        return null;
    }

    /**
     * Set up billing on a sub-account by linking it to the MCC's payments account.
     *
     * @param  string  $subAccountId  The sub-account to enable billing on
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
            if (! $paymentsAccount) {
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

            // MutateBillingSetupRequest is the one mutate request in the V26 SDK
            // with no validate_only field, so a dry run cannot be validated
            // server-side — it can only be not sent. Without this,
            // ->dryRun() would still link the sub-account to a live payments
            // profile.
            if ($this->isDryRun()) {
                Log::info('Dry run: skipping billing setup mutate (the API offers no validate_only for it)', [
                    'sub_account_id' => $subAccountId,
                    'payments_account' => $paymentsAccount,
                ]);

                return true;
            }

            $request = MutateBillingSetupRequest::build($subAccountId, $operation);

            $billingSetupServiceClient = $this->client->getBillingSetupServiceClient();
            $response = $billingSetupServiceClient->mutateBillingSetup($request);

            Log::info('Billing setup created for sub-account', [
                'sub_account_id' => $subAccountId,
                'resource_name' => $response->getResult()->getResourceName(),
            ]);

            return true;
        } catch (\Throwable $e) {
            report($e);
            Log::error('Failed to set up billing for sub-account: '.$e->getMessage(), [
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
            $query = "SELECT billing_setup.id, billing_setup.status FROM billing_setup WHERE billing_setup.status IN ('PENDING', 'APPROVED_HELD', 'APPROVED')";
            $request = new SearchGoogleAdsRequest([
                'customer_id' => $accountId,
                'query' => $query,
            ]);

            $response = $this->client->getGoogleAdsServiceClient()->search($request);
            foreach ($response as $row) {
                return true;
            }

            return false;
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Could not check billing setup status', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
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

            $matches = [];
            $manager = preg_replace('/\D/', '', (string) $this->customer->google_ads_manager_customer_id);
            foreach ($response->getPaymentsAccounts() as $account) {
                $id = str_replace('-', '', (string) $account->getPaymentsAccountId());
                if ($manager && $account->getPayingManagerCustomer() === 'customers/'.$manager
                    && $account->getCurrencyCode() === $this->customer->billingCurrency()
                    && in_array($id, config('billing.agency_funding_sources.google', []), true)) {
                    $matches[] = $account->getResourceName();
                }
            }

            return count($matches) === 1 ? $matches[0] : null;
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Could not list payments accounts', [
                'sub_account_id' => $subAccountId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
