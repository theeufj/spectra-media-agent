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
            $this->error('Failed to build Google Ads client: ' . $e->getMessage());
            Log::error('[MccEligibility] Failed to build client: ' . $e->getMessage());
            return self::FAILURE;
        }

        $request = new CreateCustomerClientRequest([
            'customer_id'     => $mccId,
            'customer_client' => new Customer([
                'descriptive_name' => 'API eligibility probe (validate_only)',
                'currency_code'    => 'AUD',
                'time_zone'        => 'Australia/Sydney',
            ]),
            'validate_only'   => true, // dry-run: runs eligibility checks, creates nothing
        ]);

        try {
            $client->getCustomerServiceClient()->createCustomerClient($request);
        } catch (ApiException $e) {
            // Expected while ineligible: CREATION_DENIED_INELIGIBLE_MCC.
            if (str_contains($e->getMessage(), 'CREATION_DENIED_INELIGIBLE_MCC')) {
                return $this->reportIneligible($client, $mcc, $mccId);
            }

            // Any other API error is unexpected — surface it loudly.
            $this->error('Unexpected API error during probe: ' . $e->getBasicMessage());
            Log::error('[MccEligibility] Unexpected API error: ' . $e->getMessage());
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

        $spent = $this->lifetimeSpend($client, $mccId);
        $context = $spent !== null
            ? sprintf('Lifetime spend across MCC: %s (threshold is ~US$1,000).', number_format($spent, 2) . ' AUD')
            : 'Lifetime spend could not be read.';

        $this->warn("❌ MCC {$mccId} is NOT YET eligible to create accounts. {$context}");
        Log::info("[MccEligibility] MCC {$mccId} not yet eligible. {$context}");

        return self::SUCCESS; // Not an error condition — this is the expected waiting state.
    }

    /** Sum lifetime cost across all non-manager client accounts under the MCC (best-effort). */
    private function lifetimeSpend($client, string $mccId): ?float
    {
        try {
            $svc = $client->getGoogleAdsServiceClient();
            $tree = new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                'customer_id' => $mccId,
                'query'       => 'SELECT customer_client.id, customer_client.manager FROM customer_client',
            ]);

            $total = 0.0;
            foreach ($svc->search($tree)->iterateAllElements() as $row) {
                $cc = $row->getCustomerClient();
                if ($cc->getManager()) {
                    continue;
                }
                try {
                    $costReq = new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                        'customer_id' => (string) $cc->getId(),
                        'query'       => 'SELECT metrics.cost_micros FROM customer',
                    ]);
                    foreach ($svc->search($costReq)->iterateAllElements() as $costRow) {
                        $total += $costRow->getMetrics()->getCostMicros() / 1_000_000;
                    }
                } catch (\Throwable $e) {
                    // Skip accounts we can't read (e.g. cancelled / no permission).
                }
            }

            return $total;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function buildMccClient(MccAccount $mcc, string $mccId): \Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient
    {
        $configPath = storage_path('app/google_ads_php.ini');

        $oAuth2Credential = (new OAuth2TokenBuilder())
            ->fromFile($configPath)
            ->withRefreshToken($mcc->getDecryptedRefreshToken())
            ->build();

        return (new GoogleAdsClientBuilder())
            ->fromFile($configPath)
            ->withOAuth2Credential($oAuth2Credential)
            ->withLoginCustomerId($mccId)
            ->build();
    }
}
