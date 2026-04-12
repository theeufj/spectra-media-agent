<?php

namespace App\Services\FacebookAds;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Facebook Token Utility — Platform System User Token Only.
 *
 * This service validates the platform-level System User token.
 * It does NOT manage per-customer tokens (prohibited by architecture rules).
 *
 * @see config/platform_architecture.php
 * @see config('services.facebook.system_user_token')
 */
class TokenService
{
    protected string $apiVersion = 'v22.0';
    protected string $graphApiUrl = 'https://graph.facebook.com';

    /**
     * Debug/inspect the platform System User token.
     *
     * Useful for admin health checks to verify the token is still valid.
     */
    public function debugSystemUserToken(): ?array
    {
        $token = config('services.facebook.system_user_token');

        if (!$token) {
            return null;
        }

        return $this->debugToken($token);
    }

    /**
     * Check if the platform System User token is valid and not expiring.
     */
    public function checkSystemTokenHealth(): array
    {
        $token = config('services.facebook.system_user_token');

        if (!$token) {
            return [
                'valid' => false,
                'error' => 'No System User token configured. Set FACEBOOK_SYSTEM_USER_TOKEN in .env',
            ];
        }

        $debugInfo = $this->debugToken($token);

        if (!$debugInfo) {
            return [
                'valid' => false,
                'error' => 'Could not inspect token',
            ];
        }

        return [
            'valid' => $debugInfo['is_valid'] ?? false,
            'type' => $debugInfo['type'] ?? 'unknown',
            'app_id' => $debugInfo['app_id'] ?? null,
            'expires_at' => $debugInfo['expires_at'] ?? 0,
            'scopes' => $debugInfo['scopes'] ?? [],
        ];
    }

    /**
     * Inspect a token using the Facebook Debug Token endpoint.
     */
    protected function debugToken(string $accessToken): ?array
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
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Exception debugging Facebook token: ' . $e->getMessage());
            return null;
        }
    }
}
