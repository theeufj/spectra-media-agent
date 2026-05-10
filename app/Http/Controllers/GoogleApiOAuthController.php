<?php

namespace App\Http\Controllers;

use App\Models\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;

class GoogleApiOAuthController extends Controller
{
    // Google API scopes required for verification submission
    private const SCOPES = [
        'https://www.googleapis.com/auth/adwords',
        'https://www.googleapis.com/auth/tagmanager.publish',
        'https://www.googleapis.com/auth/tagmanager.edit.containers',
        'https://www.googleapis.com/auth/tagmanager.readonly',
        'https://www.googleapis.com/auth/analytics.edit',
        'https://www.googleapis.com/auth/analytics.readonly',
    ];

    public function show(Request $request)
    {
        $existing = Connection::where('user_id', Auth::id())
            ->where('platform', 'google_api')
            ->first();

        return Inertia::render('Settings/GoogleApiConnect', [
            'connection' => $existing ? [
                'connected_at' => $existing->updated_at->toISOString(),
                'scopes'       => $existing->scopes ?? [],
            ] : null,
        ]);
    }

    public function redirect()
    {
        return Socialite::driver('google')
            ->redirectUrl(route('google-api.callback'))
            ->scopes(self::SCOPES)
            ->with([
                'access_type' => 'offline',
                'prompt'      => 'consent',
            ])
            ->stateless()
            ->redirect();
    }

    public function callback(Request $request)
    {
        $googleUser = Socialite::driver('google')
            ->redirectUrl(route('google-api.callback'))
            ->stateless()
            ->user();

        Connection::updateOrCreate(
            [
                'user_id'  => Auth::id(),
                'platform' => 'google_api',
            ],
            [
                'access_token'  => $googleUser->token,
                'refresh_token' => $googleUser->refreshToken ?? null,
                'expires_at'    => now()->addSeconds($googleUser->expiresIn ?? 3600),
                'account_id'    => $googleUser->getId(),
                'account_name'  => $googleUser->getName(),
                'scopes'        => self::SCOPES,
            ]
        );

        return redirect()->route('google-api.success');
    }

    public function success()
    {
        $connection = Connection::where('user_id', Auth::id())
            ->where('platform', 'google_api')
            ->first();

        if (!$connection) {
            return redirect()->route('google-api.show');
        }

        return Inertia::render('Settings/GoogleApiSuccess', [
            'account_name'  => $connection->account_name,
            'account_id'    => $connection->account_id,
            'scopes'        => $connection->scopes ?? [],
            'connected_at'  => $connection->updated_at->toISOString(),
        ]);
    }

    public function verify()
    {
        $connection = Connection::where('user_id', Auth::id())
            ->where('platform', 'google_api')
            ->first();

        if (!$connection) {
            return redirect()->route('google-api.show');
        }

        $token = $this->getFreshAccessToken($connection);

        return Inertia::render('Settings/GoogleApiVerify', [
            'account_name' => $connection->account_name,
            'googleAds'    => $this->fetchGoogleAdsAccounts($token),
            'tagManager'   => $this->fetchTagManagerAccounts($token),
            'analytics'    => $this->fetchAnalyticsAccounts($token),
        ]);
    }

    public function disconnect()
    {
        Connection::where('user_id', Auth::id())
            ->where('platform', 'google_api')
            ->delete();

        return redirect()->route('google-api.show')
            ->with('status', 'Google API connection removed.');
    }

    // -------------------------------------------------------------------------

    private function getFreshAccessToken(Connection $connection): string
    {
        // Token still valid — return as-is
        if ($connection->expires_at && $connection->expires_at->isFuture()) {
            return $connection->access_token;
        }

        // Refresh using stored refresh token
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type'    => 'refresh_token',
        ]);

        if (!$response->successful()) {
            Log::warning('GoogleApiOAuth: token refresh failed', ['body' => $response->body()]);
            return $connection->access_token; // fall back, call may fail gracefully
        }

        $data = $response->json();
        $connection->update([
            'access_token' => $data['access_token'],
            'expires_at'   => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return $data['access_token'];
    }

    private function fetchGoogleAdsAccounts(string $token): array
    {
        try {
            $ini = @parse_ini_file(storage_path('app/google_ads_php.ini')) ?: [];
            $developerToken = $ini['developerToken'] ?? '';

            $response = Http::withToken($token)
                ->withHeaders(['developer-token' => $developerToken])
                ->get('https://googleads.googleapis.com/v19/customers:listAccessibleCustomers');

            if (!$response->successful()) {
                return ['error' => $response->json('error.message') ?? 'API call failed', 'accounts' => []];
            }

            $names = $response->json('resourceNames') ?? [];
            return [
                'error'    => null,
                'accounts' => array_map(fn($n) => ['resource_name' => $n, 'id' => str_replace('customers/', '', $n)], $names),
                'count'    => count($names),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'accounts' => []];
        }
    }

    private function fetchTagManagerAccounts(string $token): array
    {
        try {
            $response = Http::withToken($token)
                ->get('https://tagmanager.googleapis.com/tagmanager/v2/accounts');

            if (!$response->successful()) {
                return ['error' => $response->json('error.message') ?? 'API call failed', 'accounts' => []];
            }

            $accounts = $response->json('account') ?? [];
            return [
                'error'    => null,
                'accounts' => array_map(fn($a) => [
                    'id'   => $a['accountId'] ?? '',
                    'name' => $a['name'] ?? '',
                ], $accounts),
                'count' => count($accounts),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'accounts' => []];
        }
    }

    private function fetchAnalyticsAccounts(string $token): array
    {
        try {
            $response = Http::withToken($token)
                ->get('https://analyticsadmin.googleapis.com/v1beta/accounts');

            if (!$response->successful()) {
                return ['error' => $response->json('error.message') ?? 'API call failed', 'accounts' => []];
            }

            $accounts = $response->json('accounts') ?? [];
            return [
                'error'    => null,
                'accounts' => array_map(fn($a) => [
                    'name'         => $a['displayName'] ?? '',
                    'resource'     => $a['name'] ?? '',
                    'region'       => $a['regionCode'] ?? '',
                ], $accounts),
                'count' => count($accounts),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'accounts' => []];
        }
    }
}
