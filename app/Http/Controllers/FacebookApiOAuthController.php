<?php

namespace App\Http\Controllers;

use App\Models\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;

class FacebookApiOAuthController extends Controller
{
    private const SCOPES = [
        'ads_management',
        'ads_read',
        'business_management',
        'pages_read_engagement',
        'pages_show_list',
    ];

    private const GRAPH = 'https://graph.facebook.com/v22.0';

    public function show()
    {
        $existing = Connection::where('user_id', Auth::id())
            ->where('platform', 'facebook_api')
            ->first();

        return Inertia::render('Settings/FacebookApiConnect', [
            'connection' => $existing ? [
                'connected_at' => $existing->updated_at->toISOString(),
                'scopes'       => $existing->scopes ?? [],
                'account_name' => $existing->account_name,
                'expires_at'   => $existing->expires_at?->toISOString(),
            ] : null,
        ]);
    }

    public function redirect()
    {
        return Socialite::driver('facebook')
            ->redirectUrl(route('facebook-api.callback'))
            ->setScopes(self::SCOPES)  // setScopes replaces defaults (avoids Socialite adding email)
            ->with(['config_id' => config('services.facebook.config_id')])
            ->stateless()
            ->redirect();
    }

    public function callback()
    {
        $fbUser = Socialite::driver('facebook')
            ->redirectUrl(route('facebook-api.callback'))
            ->stateless()
            ->user();

        // Exchange short-lived for long-lived token (~60 days)
        $exchanged = $this->exchangeForLongLivedToken($fbUser->token);
        [$accessToken, $expiresAt] = $exchanged
            ? [$exchanged['access_token'], now()->addSeconds($exchanged['expires_in'] ?? 5183944)]
            : [$fbUser->token, now()->addHours(2)];

        Connection::updateOrCreate(
            ['user_id' => Auth::id(), 'platform' => 'facebook_api'],
            [
                'access_token'  => $accessToken,
                'refresh_token' => null,
                'expires_at'    => $expiresAt,
                'account_id'    => $fbUser->getId(),
                'account_name'  => $fbUser->getName(),
                'scopes'        => self::SCOPES,
            ]
        );

        return redirect()->route('facebook-api.success');
    }

    public function success()
    {
        $connection = Connection::where('user_id', Auth::id())
            ->where('platform', 'facebook_api')
            ->first();

        if (!$connection) {
            return redirect()->route('facebook-api.show');
        }

        return Inertia::render('Settings/FacebookApiSuccess', [
            'account_name' => $connection->account_name,
            'account_id'   => $connection->account_id,
            'scopes'       => $connection->scopes ?? [],
            'connected_at' => $connection->updated_at->toISOString(),
            'expires_at'   => $connection->expires_at?->toISOString(),
        ]);
    }

    public function verify()
    {
        $connection = Connection::where('user_id', Auth::id())
            ->where('platform', 'facebook_api')
            ->first();

        if (!$connection) {
            return redirect()->route('facebook-api.show');
        }

        $tokenExpired = $connection->expires_at && $connection->expires_at->isPast();
        $token = $connection->access_token;

        return Inertia::render('Settings/FacebookApiVerify', [
            'account_name'  => $connection->account_name,
            'token_expired' => $tokenExpired,
            'identity'      => $tokenExpired ? ['error' => 'Token expired — re-authorise to refresh.'] : $this->fetchIdentity($token),
            'adAccounts'    => $tokenExpired ? ['error' => 'Token expired.', 'accounts' => []] : $this->fetchAdAccounts($token),
            'businesses'    => $tokenExpired ? ['error' => 'Token expired.', 'accounts' => []] : $this->fetchBusinesses($token),
        ]);
    }

    public function disconnect()
    {
        Connection::where('user_id', Auth::id())
            ->where('platform', 'facebook_api')
            ->delete();

        return redirect()->route('facebook-api.show')
            ->with('status', 'Facebook API connection removed.');
    }

    // -------------------------------------------------------------------------

    private function exchangeForLongLivedToken(string $shortToken): ?array
    {
        try {
            $response = Http::get('https://graph.facebook.com/oauth/access_token', [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => config('services.facebook.client_id'),
                'client_secret'     => config('services.facebook.client_secret'),
                'fb_exchange_token' => $shortToken,
            ]);

            $data = $response->json();
            if (!$response->successful() || empty($data['access_token'])) {
                Log::warning('FacebookApiOAuth: long-lived token exchange failed', [
                    'status' => $response->status(),
                    'body'   => $data,
                ]);
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            Log::error('FacebookApiOAuth: token exchange exception', ['message' => $e->getMessage()]);
            return null;
        }
    }

    private function fetchIdentity(string $token): array
    {
        try {
            $response = Http::get(self::GRAPH . '/me', [
                'fields'       => 'id,name,email',
                'access_token' => $token,
            ]);

            if (!$response->successful()) {
                return ['error' => $response->json('error.message') ?? 'API call failed'];
            }

            return ['error' => null, 'data' => $response->json()];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function fetchAdAccounts(string $token): array
    {
        try {
            $response = Http::get(self::GRAPH . '/me/adaccounts', [
                'fields'       => 'id,name,account_status',
                'access_token' => $token,
            ]);

            if (!$response->successful()) {
                return ['error' => $response->json('error.message') ?? 'API call failed', 'accounts' => [], 'status' => $response->status()];
            }

            $accounts = $response->json('data') ?? [];
            return [
                'error'    => null,
                'accounts' => array_map(fn($a) => [
                    'id'     => $a['id'] ?? '',
                    'name'   => $a['name'] ?? '',
                    'status' => $a['account_status'] ?? null,
                ], $accounts),
                'count' => count($accounts),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'accounts' => []];
        }
    }

    private function fetchBusinesses(string $token): array
    {
        try {
            $response = Http::get(self::GRAPH . '/me/businesses', [
                'fields'       => 'id,name',
                'access_token' => $token,
            ]);

            if (!$response->successful()) {
                return ['error' => $response->json('error.message') ?? 'API call failed', 'accounts' => [], 'status' => $response->status()];
            }

            $businesses = $response->json('data') ?? [];
            return [
                'error'    => null,
                'accounts' => array_map(fn($b) => [
                    'id'   => $b['id'] ?? '',
                    'name' => $b['name'] ?? '',
                ], $businesses),
                'count' => count($businesses),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'accounts' => []];
        }
    }
}
