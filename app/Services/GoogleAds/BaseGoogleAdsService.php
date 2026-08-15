<?php

namespace App\Services\GoogleAds;

use App\Features\PerUserGoogleToken;
use App\Models\Customer;
use App\Models\MccAccount;
use App\Services\Agents\Traits\RetryableApiOperation;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

abstract class BaseGoogleAdsService
{
    use RetryableApiOperation;

    protected string $platform = 'google_ads';

    protected ?\Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient $client = null;

    protected ?Customer $customer = null;

    /**
     * When true, every mutate this service issues is sent with validate_only.
     *
     * Google fully validates the request — required fields, policy, budget
     * shape, resource references — and applies nothing. It is the cheapest way
     * to prove a mutation is well-formed against a real account, needing no test
     * account and no second set of credentials.
     *
     * Per-instance and opt-in rather than a global mode: a mode flag can be left
     * on, set by one request and read by another, or inherited by a queue
     * worker, and a dry run that silently became live would be far worse than
     * no dry run at all.
     *
     * It cannot validate multi-step chains — nothing is created, so a later
     * operation has no ID to reference. That case needs a Google test account.
     */
    protected bool $dryRun = false;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->client = $this->buildClient();
        $this->maybeUseUserCredentials();
    }

    /**
     * Validate mutations against Google without applying them.
     *
     * Fluent and per-instance: (new AddKeyword($customer))->dryRun()->__invoke(...)
     */
    public function dryRun(bool $enabled = true): static
    {
        $this->dryRun = $enabled;

        return $this;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * Ensure the Google Ads client is properly initialized
     *
     * @throws \Exception if client is not available
     */
    protected function ensureClient(): void
    {
        if ($this->client === null) {
            throw new \Exception(
                "Google Ads client not initialized for customer {$this->customer->id}. ".
                'Please check: 1) OAuth credentials are valid, '.
                '2) Customer has connected their Google Ads account via OAuth'
            );
        }
    }

    /**
     * If the PerUserGoogleToken feature flag is active for this customer's user,
     * rebuild the Google Ads client using that user's stored OAuth refresh token
     * instead of the platform MCC credentials. Falls back silently on any error.
     */
    private function maybeUseUserCredentials(): void
    {
        $user = $this->customer?->users()->first();

        if (! $user || ! Feature::for($user)->active(PerUserGoogleToken::class)) {
            return;
        }

        $connection = $user->connections()
            ->where('platform', 'google_ads')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        if (! $connection?->refresh_token) {
            Log::warning('[GoogleAds] PerUserGoogleToken flag active but no valid connection found, using MCC credentials', [
                'customer_id' => $this->customer->id,
                'user_id' => $user->id,
            ]);

            return;
        }

        try {
            $configPath = storage_path('app/google_ads_php.ini');

            $oAuth2Credential = (new OAuth2TokenBuilder)
                ->fromFile($configPath)
                ->withRefreshToken($connection->refresh_token)
                ->build();

            $this->client = (new GoogleAdsClientBuilder)
                ->fromFile($configPath)
                ->withOAuth2Credential($oAuth2Credential)
                ->build();

            Log::info('[GoogleAds] Using per-user OAuth token', [
                'customer_id' => $this->customer->id,
                'user_id' => $user->id,
                'connection_id' => $connection->id,
            ]);
        } catch (\Exception $e) {
            Log::warning('[GoogleAds] Failed to build per-user client, retaining MCC credentials', [
                'customer_id' => $this->customer->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function buildClient(): ?\Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient
    {
        try {
            $configPath = storage_path('app/google_ads_php.ini');

            if (! file_exists($configPath)) {
                Log::warning("Google Ads config file not found at {$configPath}. Skipping client build.");

                return null;
            }

            // All API calls use the platform MCC credentials.
            // Resolved from DB (mcc_accounts table) with env fallback.
            $mccAccount = MccAccount::getActive();

            if (! $mccAccount) {
                Log::error('No active MCC account configured (checked DB and env)');

                return null;
            }

            $mccRefreshToken = $mccAccount->getDecryptedRefreshToken();
            // Strip dashes — Google Ads API requires plain numeric IDs (e.g. 8701023448 not 870-102-3448)
            $mccCustomerId = preg_replace('/[^0-9]/', '', $mccAccount->google_customer_id);

            $oAuth2Credential = (new OAuth2TokenBuilder)
                ->fromFile($configPath)
                ->withRefreshToken($mccRefreshToken)
                ->build();

            $builder = (new GoogleAdsClientBuilder)
                ->fromFile($configPath)
                ->withOAuth2Credential($oAuth2Credential)
                ->withLoginCustomerId($mccCustomerId);

            return $builder->build();
        } catch (\Exception $e) {
            Log::error("Failed to build Google Ads client for customer {$this->customer->id}: ".$e->getMessage(), [
                'customer_id' => $this->customer->id,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    protected function logInfo(string $message, array $context = []): void
    {
        Log::info('[GoogleAds] '.$message, $context);
    }

    protected function logError(string $message, $exception = null): void
    {
        $context = [];

        // \Throwable, not \Exception: an \Error (TypeError, undefined method,
        // missing class) would otherwise be logged with no detail attached —
        // exactly the failures hardest to diagnose from a bare message.
        if ($exception instanceof \Throwable) {
            $context = [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ];

            // Log::error() alone does not reach the admin exception dashboard;
            // runtime_exceptions is fed by Queue::failing and the report
            // handler. Without this, a caught Google Ads error is invisible
            // there — and every catch block in these services returns null and
            // carries on, so nothing else would surface it either.
            report($exception);
        }

        Log::error('[GoogleAds] '.$message, $context);
    }

    /**
     * Execute a GAQL search query using the V22 SearchGoogleAdsRequest pattern.
     */
    protected function searchQuery(string $customerId, string $query): \Google\ApiCore\PagedListResponse
    {
        $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();

        return $googleAdsServiceClient->search(
            new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                'customer_id' => $customerId,
                'query' => $query,
            ])
        );
    }

    /**
     * Get the underlying GoogleAdsClient instance.
     */
    public function getClient(): ?\Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient
    {
        return $this->client;
    }

    /**
     * Execute a GAQL search query with automatic retry and circuit breaker protection.
     */
    protected function searchQueryWithRetry(string $customerId, string $query, string $operationName = 'search_query'): \Google\ApiCore\PagedListResponse
    {
        return $this->executeWithRetry(
            fn () => $this->searchQuery($customerId, $query),
            $operationName,
            ['customer_id' => $customerId]
        );
    }
}
