<?php

namespace App\Console\Commands;

use App\Models\MccAccount;
use App\Models\User;
use App\Notifications\MccAccountCreationEligible;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\V22\Resources\Customer;
use Google\Ads\GoogleAds\V22\Services\CreateCustomerClientRequest;
use Google\ApiCore\ApiException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Probes whether the platform MCC is yet allowed to create client accounts via the API.
 *
 * A fresh manager account is denied account creation (CREATION_DENIED_INELIGIBLE_MCC)
 * until a linked account has spent > US$1,000 with a clean policy history. This command
 * performs a validate_only createCustomerClient call — it runs Google's real eligibility
 * checks WITHOUT creating any account — and alerts admins once the MCC becomes eligible.
 */
class CheckMccAccountCreationEligibility extends Command
{
    protected $signature = 'googleads:check-mcc-eligibility';

    protected $description = 'Probe (validate_only) whether the MCC can create client accounts via the API yet; alert admins when eligible';

    /** Cache key guarding against re-notifying every day once eligible. */
    private const NOTIFIED_KEY = 'mcc_account_creation_eligible_notified';

    public function handle(): int
    {
        $mcc = MccAccount::getActive();
        if (! $mcc) {
            $this->error('No active MCC account configured.');
            Log::error('[MccEligibility] No active MCC account configured.');

            return self::FAILURE;
        }

        $mccId = preg_replace('/[^0-9]/', '', $mcc->google_customer_id);

        try {
            $client = $this->buildMccClient($mcc, $mccId);
        } catch (\Throwable $e) {
            $this->error('Failed to build Google Ads client: '.$e->getMessage());
            Log::error('[MccEligibility] Failed to build client: '.$e->getMessage());

            return self::FAILURE;
        }

        $request = new CreateCustomerClientRequest([
            'customer_id' => $mccId,
            'customer_client' => new Customer([
                'descriptive_name' => 'API eligibility probe (validate_only)',
                'currency_code' => 'AUD',
                'time_zone' => 'Australia/Sydney',
            ]),
            'validate_only' => true, // dry-run: runs eligibility checks, creates nothing
        ]);

        try {
            $client->getCustomerServiceClient()->createCustomerClient($request);
        } catch (ApiException $e) {
            // Expected while ineligible: CREATION_DENIED_INELIGIBLE_MCC.
            if (str_contains($e->getMessage(), 'CREATION_DENIED_INELIGIBLE_MCC')) {
                return $this->reportIneligible($client, $mcc, $mccId);
            }

            // Any other API error is unexpected — surface it loudly.
            $this->error('Unexpected API error during probe: '.$e->getBasicMessage());
            Log::error('[MccEligibility] Unexpected API error: '.$e->getMessage());

            return self::FAILURE;
        }

        // No exception → validate_only passed → the MCC can now create accounts.
        return $this->reportEligible($mccId);
    }

    private function reportEligible(string $mccId): int
    {
        $this->info("✅ MCC {$mccId} is now ELIGIBLE to create client accounts via the API.");
        Log::info("[MccEligibility] MCC {$mccId} is now eligible to create client accounts.");

        // Notify admins once (guard against a daily repeat email).
        if (! Cache::get(self::NOTIFIED_KEY)) {
            $admins = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->get();
            foreach ($admins as $admin) {
                $admin->notify(new MccAccountCreationEligible($mccId));
            }
            Cache::forever(self::NOTIFIED_KEY, true);
            $this->info("Notified {$admins->count()} admin(s).");
        } else {
            $this->line('Admins already notified previously; skipping repeat alert.');
        }

        return self::SUCCESS;
    }

    private function reportIneligible($client, MccAccount $mcc, string $mccId): int
    {
        // Clear the notified flag so a future re-eligibility (e.g. after a suspension) re-alerts.
        Cache::forget(self::NOTIFIED_KEY);

        $spend = $this->lifetimeSpend($client, $mccId);

        if ($spend === null) {
            $this->warn("❌ MCC {$mccId} is NOT YET eligible to create accounts. Lifetime spend could not be read.");
            Log::info("[MccEligibility] MCC {$mccId} not yet eligible; spend unreadable.");

            return self::SUCCESS;
        }

        $threshold = (float) config('googleads.account_creation_threshold_usd', 1000.0);
        $remaining = max(0.0, $threshold - $spend['usd']);

        $perCurrency = collect($spend['by_currency'])
            ->map(fn ($amt, $cur) => $cur.' '.number_format($amt, 2))
            ->implode(', ');

        $context = sprintf(
            'Lifetime spend: %s ≈ US$%s of US$%s (US$%s to go).',
            $perCurrency ?: 'none',
            number_format($spend['usd'], 2),
            number_format($threshold, 2),
            number_format($remaining, 2)
        );

        $this->warn("❌ MCC {$mccId} is NOT YET eligible to create accounts.");
        $this->line("   {$context}");

        // An account we cannot read may hold spend that counts toward the threshold,
        // so the total above is a floor, not a certainty. Previously swallowed.
        if ($spend['unreadable']) {
            $this->warn(sprintf(
                '   %d account(s) could not be read, so this total is a lower bound: %s',
                count($spend['unreadable']),
                implode(', ', $spend['unreadable'])
            ));
        }

        if ($spend['unknown_currencies']) {
            $this->warn('   No USD rate configured for: '.implode(', ', $spend['unknown_currencies'])
                .' — excluded from the USD total (see config/googleads.php usd_rates).');
        }

        Log::info("[MccEligibility] MCC {$mccId} not yet eligible. {$context}", [
            'unreadable_accounts' => $spend['unreadable'],
            'unknown_currencies' => $spend['unknown_currencies'],
        ]);

        return self::SUCCESS; // Not an error condition — this is the expected waiting state.
    }

    /**
     * Lifetime cost across all non-manager client accounts under the MCC.
     *
     * Each account reports cost in its own currency, so totals are kept per
     * currency and converted to USD only for comparison against the threshold.
     * Accounts that cannot be read are reported rather than silently skipped —
     * they may hold spend that counts, which would make the total an underestimate.
     *
     * @return array{usd: float, by_currency: array<string,float>, unreadable: list<string>, unknown_currencies: list<string>}|null
     */
    private function lifetimeSpend($client, string $mccId): ?array
    {
        try {
            $svc = $client->getGoogleAdsServiceClient();
            $tree = new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                'customer_id' => $mccId,
                'query' => 'SELECT customer_client.id, customer_client.descriptive_name, customer_client.currency_code, customer_client.manager FROM customer_client',
            ]);

            $byCurrency = [];
            $unreadable = [];

            foreach ($svc->search($tree)->iterateAllElements() as $row) {
                $cc = $row->getCustomerClient();
                if ($cc->getManager()) {
                    continue;
                }

                $id = (string) $cc->getId();
                $currency = strtoupper($cc->getCurrencyCode() ?: '');

                try {
                    $costReq = new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                        'customer_id' => $id,
                        'query' => 'SELECT metrics.cost_micros FROM customer',
                    ]);
                    foreach ($svc->search($costReq)->iterateAllElements() as $costRow) {
                        $amount = $costRow->getMetrics()->getCostMicros() / 1_000_000;
                        $byCurrency[$currency] = ($byCurrency[$currency] ?? 0.0) + $amount;
                    }
                } catch (\Throwable $e) {
                    $unreadable[] = sprintf('%s (%s)', $id, $cc->getDescriptiveName() ?: 'unnamed');
                }
            }

            $rates = config('googleads.usd_rates', []);
            $usd = 0.0;
            $unknownCurrencies = [];

            foreach ($byCurrency as $currency => $amount) {
                if (! isset($rates[$currency])) {
                    $unknownCurrencies[] = $currency ?: '(none)';

                    continue;
                }
                $usd += $amount * (float) $rates[$currency];
            }

            return [
                'usd' => $usd,
                'by_currency' => $byCurrency,
                'unreadable' => $unreadable,
                'unknown_currencies' => $unknownCurrencies,
            ];
        } catch (\Throwable $e) {
            Log::warning('[MccEligibility] Could not read lifetime spend: '.$e->getMessage());

            return null;
        }
    }

    private function buildMccClient(MccAccount $mcc, string $mccId): \Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient
    {
        $configPath = storage_path('app/google_ads_php.ini');

        $oAuth2Credential = (new OAuth2TokenBuilder)
            ->fromFile($configPath)
            ->withRefreshToken($mcc->getDecryptedRefreshToken())
            ->build();

        return (new GoogleAdsClientBuilder)
            ->fromFile($configPath)
            ->withOAuth2Credential($oAuth2Credential)
            ->withLoginCustomerId($mccId)
            ->build();
    }
}
