<?php

namespace App\Services\FacebookAds;

use App\Models\Customer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Facebook Token Management Service
 * 
 * Handles:
 * - Short-lived to long-lived token exchange
 * - Token refresh operations
 * - Token expiry checking
 * - Debug token inspection
 */
class TokenService
{
    protected string $apiVersion = 'v18.0';
    protected string $graphApiUrl = 'https://graph.facebook.com';

    /**
     * Exchange a short-lived token for a long-lived token.
     * Long-lived tokens are valid for ~60 days.
     *
     * @param string $shortLivedToken
     * @return array{success: bool, access_token?: string, expires_in?: int, error?: string}
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        try {
            $response = Http::get("{$this->graphApiUrl}/{$this->apiVersion}/oauth/access_token", [
                'grant_type' => 'fb_exchange_token',
                'client_id' => config('services.facebook.client_id'),
                'client_secret' => config('services.facebook.client_secret'),
                'fb_exchange_token' => $shortLivedToken,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'access_token' => $data['access_token'],
                    'expires_in' => $data['expires_in'] ?? 5184000, // Default 60 days
                    'token_type' => $data['token_type'] ?? 'bearer',
                ];
            }

            Log::error('Failed to exchange Facebook token', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => $response->json()['error']['message'] ?? 'Token exchange failed',
            ];

        } catch (\Exception $e) {
            Log::error('Exception exchanging Facebook token: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Refresh a long-lived token.
     * Note: Facebook doesn't have a traditional refresh flow. You can only exchange
     * an existing valid long-lived token for a new one before it expires.
     *
     * @param string $longLivedToken
     * @return array{success: bool, access_token?: string, expires_in?: int, error?: string}
     */
    public function refreshLongLivedToken(string $longLivedToken): array
    {
        // Facebook's "refresh" is just another exchange operation
        // The token must still be valid (not expired) for this to work
        return $this->exchangeForLongLivedToken($longLivedToken);
    }

    /**
     * Debug/inspect a token to get its metadata.
     *
     * @param string $accessToken
     * @return array|null
     */
    public function debugToken(string $accessToken): ?array
    {
        try {
            $appAccessToken = config('services.facebook.client_id') . '|' . config('services.facebook.client_secret');
            
            $response = Http::get("{$this->graphApiUrl}/{$this->apiVersion}/debug_token", [
                'input_token' => $accessToken,
                'access_token' => $appAccessToken,
            ]);

            if ($response->successful()) {
                return $response->json()['data'] ?? null;
            }

            Log::error('Failed to debug Facebook token', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Exception debugging Facebook token: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if a customer's token is valid and not expiring soon.
     *
     * @param Customer $customer
     * @return array{valid: bool, expires_in_days?: int, needs_refresh: bool, error?: string}
     */
    public function checkTokenStatus(Customer $customer): array
    {
        if (empty($customer->facebook_ads_access_token)) {
            return [
                'valid' => false,
                'needs_refresh' => false,
                'error' => 'No access token stored',
            ];
        }

        try {
            $token = Crypt::decryptString($customer->facebook_ads_access_token);
            $debugInfo = $this->debugToken($token);

            if (!$debugInfo) {
                return [
                    'valid' => false,
                    'needs_refresh' => false,
                    'error' => 'Could not debug token',
                ];
            }

            $isValid = $debugInfo['is_valid'] ?? false;
            $expiresAt = isset($debugInfo['expires_at']) ? Carbon::createFromTimestamp($debugInfo['expires_at']) : null;
            
            if (!$isValid) {
                return [
                    'valid' => false,
                    'needs_refresh' => false,
                    'error' => 'Token is invalid',
                ];
            }

            if ($expiresAt) {
                $daysUntilExpiry = Carbon::now()->diffInDays($expiresAt, false);
                
                return [
                    'valid' => true,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'expires_in_days' => max(0, $daysUntilExpiry),
                    'needs_refresh' => $daysUntilExpiry <= 7, // Refresh if expiring within 7 days
                    'scopes' => $debugInfo['scopes'] ?? [],
                ];
            }

            // Token doesn't expire (rare)
            return [
                'valid' => true,
                'expires_at' => null,
                'expires_in_days' => null,
                'needs_refresh' => false,
            ];

        } catch (\Exception $e) {
            Log::error('Exception checking token status: ' . $e->getMessage(), [
                'customer_id' => $customer->id,
            ]);
            
            return [
                'valid' => false,
                'needs_refresh' => false,
                'error' => 'Failed to check token: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Refresh a customer's token if needed.
     *
     * @param Customer $customer
     * @return array{success: bool, refreshed: bool, error?: string}
     */
    public function refreshCustomerTokenIfNeeded(Customer $customer): array
    {
        $status = $this->checkTokenStatus($customer);

        if (!$status['valid']) {
            return [
                'success' => false,
                'refreshed' => false,
                'error' => $status['error'] ?? 'Token is invalid and cannot be refreshed',
            ];
        }

        if (!$status['needs_refresh']) {
            return [
                'success' => true,
                'refreshed' => false,
                'message' => 'Token does not need refresh yet',
            ];
        }

        try {
            $currentToken = Crypt::decryptString($customer->facebook_ads_access_token);
            $result = $this->refreshLongLivedToken($currentToken);

            if ($result['success']) {
                $expiresAt = Carbon::now()->addSeconds($result['expires_in']);
                
                $customer->update([
                    'facebook_ads_access_token' => Crypt::encryptString($result['access_token']),
                    'facebook_token_expires_at' => $expiresAt,
                    'facebook_token_refreshed_at' => now(),
                    'facebook_token_is_long_lived' => true,
                ]);

                Log::info('Facebook token refreshed successfully', [
                    'customer_id' => $customer->id,
                    'expires_at' => $expiresAt->toIso8601String(),
                ]);

                return [
                    'success' => true,
                    'refreshed' => true,
                    'expires_at' => $expiresAt->toIso8601String(),
                ];
            }

            return [
                'success' => false,
                'refreshed' => false,
                'error' => $result['error'] ?? 'Token refresh failed',
            ];

        } catch (\Exception $e) {
            Log::error('Exception refreshing customer token: ' . $e->getMessage(), [
                'customer_id' => $customer->id,
            ]);
            
            return [
                'success' => false,
                'refreshed' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Exchange and store a long-lived token for a customer after OAuth.
     *
     * @param Customer $customer
     * @param string $shortLivedToken
     * @return array{success: bool, error?: string}
     */
    public function storeAsLongLivedToken(Customer $customer, string $shortLivedToken): array
    {
        $result = $this->exchangeForLongLivedToken($shortLivedToken);

        if ($result['success']) {
            $expiresAt = Carbon::now()->addSeconds($result['expires_in']);
            
            $customer->update([
                'facebook_ads_access_token' => Crypt::encryptString($result['access_token']),
                'facebook_token_expires_at' => $expiresAt,
                'facebook_token_refreshed_at' => now(),
                'facebook_token_is_long_lived' => true,
            ]);

            Log::info('Long-lived Facebook token stored', [
                'customer_id' => $customer->id,
                'expires_at' => $expiresAt->toIso8601String(),
            ]);

            return [
                'success' => true,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        }

        return [
            'success' => false,
            'error' => $result['error'] ?? 'Failed to exchange token',
        ];
    }
}
