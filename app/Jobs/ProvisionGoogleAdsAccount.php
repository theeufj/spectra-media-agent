<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Customer;
use App\Models\MccAccount;
use App\Services\GoogleAds\CreateAndLinkManagedAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Create a Google Ads account under the MCC for a customer who has none.
 *
 * Ten customers going back to March reached this platform without an ad
 * account, because a fresh manager account cannot create client accounts until
 * it has managed roughly US$1,000 of spend. That gate has now cleared, so the
 * step that was impossible becomes the default.
 *
 * Nothing about the previous behaviour said so. Conversion tracking setup ran,
 * failed on "Google Ads account not connected", retried three times and paged
 * an admin — for a condition the customer could do nothing about and the
 * platform had never attempted to fix.
 */
class ProvisionGoogleAdsAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300];

    public function __construct(public Customer $customer) {}

    /**
     * Is this a currency Google will accept?
     *
     * Deliberately a real check rather than a length test: "DOL" is three
     * characters and means nothing.
     */
    private function isValidCurrency(string $code): bool
    {
        if (! preg_match('/^[A-Z]{3}$/', $code)) {
            return false;
        }

        return in_array($code, [
            'AUD', 'CAD', 'CHF', 'CNY', 'DKK', 'EUR', 'GBP', 'HKD', 'IDR', 'ILS',
            'INR', 'JPY', 'KRW', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK',
            'SGD', 'THB', 'TRY', 'TWD', 'USD', 'VND', 'ZAR', 'BRL', 'ARS', 'CLP',
            'COP', 'CZK', 'HUF', 'NGN', 'AED', 'SAR', 'EGP', 'PKR', 'BDT', 'LKR',
        ], true);
    }

    public function handle(): void
    {
        $customer = $this->customer->fresh();

        if (! $customer) {
            return;
        }

        if ($customer->google_ads_customer_id) {
            return;
        }

        // A customer bringing their own account is mid-invitation. Creating a
        // second account for them would leave two, and the one they accepted
        // into would not be the one we advertise from.
        if (in_array($customer->google_ads_link_status, ['pending', 'active'], true)) {
            Log::info('ProvisionGoogleAdsAccount: customer is linking their own account, skipping', [
                'customer_id' => $customer->id,
                'link_status' => $customer->google_ads_link_status,
            ]);

            return;
        }

        if ($customer->is_sandbox) {
            return;
        }

        $mcc = MccAccount::getActive();

        if (! $mcc) {
            Log::error('ProvisionGoogleAdsAccount: no active MCC configured', ['customer_id' => $customer->id]);

            return;
        }

        $managerId = preg_replace('/[^0-9]/', '', $mcc->google_customer_id);

        // Google requires both, and rejects the call rather than guessing.
        $currency = strtoupper(trim((string) $customer->currency_code));
        $timezone = $customer->timezone ?: config('app.timezone');

        // An account's currency cannot be changed after it is created, ever.
        // Customer 11 carried "DOL", which is not an ISO 4217 code at all — so a
        // bad value here does not produce a failed call to retry, it produces a
        // permanently wrong account. Refuse and say so instead.
        if (! $this->isValidCurrency($currency)) {
            Log::error('ProvisionGoogleAdsAccount: refusing to create an account with an unusable currency', [
                'customer_id' => $customer->id,
                'currency_code' => $customer->currency_code,
            ]);

            AgentActivity::record(
                'onboarding',
                'google_ads_account_blocked',
                'Cannot create a Google Ads account for "'.$customer->name.'": currency "'.$customer->currency_code.'" is not a valid ISO 4217 code, and an account\'s currency cannot be changed later.',
                $customer->id,
                null,
                ['currency_code' => $customer->currency_code]
            );

            return;
        }

        $result = app(CreateAndLinkManagedAccount::class)(
            $managerId,
            $customer->name,
            $currency,
            $timezone
        );

        if (! $result || empty($result['customer_id'])) {
            // Left for the retry rather than marked failed: the commonest cause
            // is the MCC eligibility gate, which clears on its own.
            Log::error('ProvisionGoogleAdsAccount: account creation returned nothing', [
                'customer_id' => $customer->id,
                'manager' => $managerId,
            ]);

            throw new \RuntimeException('Google Ads account creation failed for customer '.$customer->id);
        }

        $customer->update([
            'google_ads_customer_id' => $result['customer_id'],
            'google_ads_manager_customer_id' => $managerId,
            'google_ads_customer_is_manager' => false,
        ]);

        AgentActivity::record(
            'onboarding',
            'google_ads_account_created',
            'Created Google Ads account '.$result['customer_id'].' for "'.$customer->name.'"',
            $customer->id,
            null,
            ['client_account' => $result['customer_id'], 'manager_account' => $managerId, 'currency' => $currency, 'timezone' => $timezone]
        );

        Log::info('ProvisionGoogleAdsAccount: account created', [
            'customer_id' => $customer->id,
            'google_ads_customer_id' => $result['customer_id'],
        ]);

        // Conversion tracking needs the account, and gave up before it existed.
        SetupConversionTracking::dispatch($customer->fresh())->delay(now()->addMinute());
    }
}
