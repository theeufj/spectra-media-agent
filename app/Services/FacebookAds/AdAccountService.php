<?php

namespace App\Services\FacebookAds;

use Illuminate\Support\Facades\Log;
use App\Models\Customer;

class AdAccountService extends BaseFacebookAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * List all accessible ad accounts for the user.
     *
     * @return ?array Array of ad accounts or null on failure
     */
    public function listAdAccounts(): ?array
    {
        try {
            $response = $this->get('/me/adaccounts', [
                'fields' => 'id,name,account_status,currency,timezone_name,business_name',
            ]);

            if ($response && isset($response['data'])) {
                Log::info("Retrieved ad accounts for customer {$this->customer->id}", [
                    'account_count' => count($response['data']),
                ]);
                return $response['data'];
            }

            Log::warning("No ad accounts found for customer {$this->customer->id}");
            return [];
        } catch (\Exception $e) {
            Log::error("Error listing ad accounts: " . $e->getMessage(), [
                'exception' => $e,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Get details of a specific ad account.
     *
     * @param string $accountId The Facebook ad account ID (without 'act_' prefix)
     * @return ?array
     */
    public function getAdAccount(string $accountId): ?array
    {
        try {
            $response = $this->get("/act_{$accountId}", [
                'fields' => 'id,name,account_status,currency,timezone_name,business_name,owner,users',
            ]);

            if ($response) {
                Log::info("Retrieved details for ad account {$accountId}", [
                    'customer_id' => $this->customer->id,
                ]);
                return $response;
            }

            return null;
        } catch (\Exception $e) {
            Log::error("Error getting ad account details: " . $e->getMessage(), [
                'exception' => $e,
                'account_id' => $accountId,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Create a new ad account.
     *
     * @param string $accountName The name of the new ad account
     * @param string $currency The currency code (e.g., 'USD')
     * @param string $timezone The timezone (e.g., 'America/New_York')
     * @return ?array Array with account ID or null on failure
     */
    public function createAdAccount(string $accountName, string $currency = 'USD', string $timezone = 'America/New_York'): ?array
    {
        try {
            $response = $this->post('/me/adaccounts', [
                'name' => $accountName,
                'currency' => $currency,
                'timezone' => $timezone,
            ]);

            if ($response && isset($response['id'])) {
                Log::info("Created new ad account for customer {$this->customer->id}", [
                    'account_id' => $response['id'],
                    'account_name' => $accountName,
                ]);
                return $response;
            }

            Log::error("Failed to create ad account", [
                'customer_id' => $this->customer->id,
                'response' => $response,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error("Error creating ad account: " . $e->getMessage(), [
                'exception' => $e,
                'account_name' => $accountName,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Get spending insights for an ad account.
     *
     * @param string $accountId The Facebook ad account ID (without 'act_' prefix)
     * @param string $dateStart Start date (YYYY-MM-DD)
     * @param string $dateEnd End date (YYYY-MM-DD)
     * @return ?array
     */
    public function getSpendInsights(string $accountId, string $dateStart, string $dateEnd): ?array
    {
        try {
            $response = $this->get("/act_{$accountId}/insights", [
                'time_range' => json_encode(['since' => $dateStart, 'until' => $dateEnd]),
                'fields' => 'account_id,spend,impressions,clicks,actions,action_values',
                'time_increment' => 'monthly',
            ]);

            if ($response && isset($response['data'])) {
                Log::info("Retrieved spend insights for ad account {$accountId}", [
                    'customer_id' => $this->customer->id,
                    'data_points' => count($response['data']),
                ]);
                return $response['data'];
            }

            return [];
        } catch (\Exception $e) {
            Log::error("Error getting spend insights: " . $e->getMessage(), [
                'exception' => $e,
                'account_id' => $accountId,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }
}
