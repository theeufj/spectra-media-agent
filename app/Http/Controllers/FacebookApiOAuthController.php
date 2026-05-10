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

        if ($tokenExpired) {
            return Inertia::render('Settings/FacebookApiVerify', [
                'account_name'  => $connection->account_name,
                'token_expired' => true,
                'identity'      => ['error' => 'Token expired.'],
                'adAccounts'    => ['error' => 'Token expired.', 'accounts' => []],
                'adInsights'    => ['error' => 'Token expired.'],
                'businesses'    => ['error' => 'Token expired.', 'accounts' => []],
                'managedPages'  => ['error' => 'Token expired.', 'pages' => []],
            ]);
        }

        $adAccounts = $this->fetchAdAccounts($token);
        $businesses = $this->fetchBusinesses($token);
        $managedPages = $this->fetchManagedPages($token);

        // Fetch account-level ad insights for the first accessible ad account
        $firstAccountId = $adAccounts['accounts'][0]['id'] ?? null;
        $adInsights = $firstAccountId ? $this->fetchAdInsights($token, $firstAccountId) : ['error' => 'No ad accounts found.'];

        // Fetch posts for the first managed page — use the page's own access token from /me/accounts
        $firstPage = $managedPages['pages'][0] ?? null;
        $firstPageId = $firstPage['id'] ?? null;
        $firstPageToken = $firstPage['page_token'] ?? $token;
        $pagePosts = $firstPageId ? $this->fetchPagePosts($firstPageToken, $firstPageId, $firstPage['name'] ?? '') : ['error' => 'No managed pages found.', 'posts' => []];

        // Fetch pages linked to first business (business_management asset demonstration)
        $firstBusinessId = $businesses['accounts'][0]['id'] ?? null;
        $businessAssets = $firstBusinessId ? $this->fetchBusinessPages($token, $firstBusinessId, $businesses['accounts'][0]['name'] ?? '') : ['error' => 'No businesses found.', 'pages' => []];

        return Inertia::render('Settings/FacebookApiVerify', [
            'account_name'      => $connection->account_name,
            'token_expired'     => false,
            'identity'          => $this->fetchIdentity($token),
            'grantedPermissions'=> $this->fetchGrantedPermissions($token),
            'adAccounts'        => $adAccounts,
            'adInsights'        => $adInsights,
            'businesses'        => $businesses,
            'businessAssets'    => $businessAssets,
            'managedPages'      => $managedPages,
            'pagePosts'         => $pagePosts,
        ]);
    }

    public function createTestCampaign(Request $request)
    {
        $connection = Connection::where('user_id', Auth::id())
            ->where('platform', 'facebook_api')
            ->first();

        if (!$connection) {
            return response()->json(['error' => 'Not connected'], 401);
        }

        $adAccountId = $request->input('ad_account_id');
        if (!$adAccountId) {
            return response()->json(['error' => 'No ad account specified'], 422);
        }

        try {
            // Graph API requires form-encoded body (not JSON). Empty PHP arrays
            // are dropped by form encoding, so special_ad_categories must be the
            // string '[]' which Facebook deserialises as an empty JSON array.
            $response = Http::post(self::GRAPH . '/' . $adAccountId . '/campaigns', [
                'name'                        => 'SiteToSpend Demo Campaign - ' . now()->format('d M Y H:i'),
                'objective'                   => 'OUTCOME_AWARENESS',
                'status'                      => 'PAUSED',
                'special_ad_categories'       => '[]',
                'is_adset_budget_sharing_enabled' => 0,
                'access_token'                => $connection->access_token,
            ]);

            if (!$response->successful()) {
                Log::warning('FacebookApiOAuth: campaign creation failed', [
                    'ad_account_id' => $adAccountId,
                    'status'        => $response->status(),
                    'body'          => $response->json(),
                ]);
                return response()->json([
                    'error'  => $response->json('error.message') ?? 'Campaign creation failed',
                    'detail' => $response->json('error.error_user_msg') ?? null,
                    'status' => $response->status(),
                ]);
            }

            $data = $response->json();
            return response()->json([
                'success'     => true,
                'campaign_id' => $data['id'] ?? null,
                'name'        => 'SiteToSpend Demo Campaign - ' . now()->format('d M Y H:i'),
                'status'      => 'PAUSED',
                'objective'   => 'OUTCOME_AWARENESS',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
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

    private function fetchAdInsights(string $token, string $adAccountId): array
    {
        try {
            $response = Http::get(self::GRAPH . '/' . $adAccountId . '/insights', [
                'fields'      => 'impressions,clicks,spend,reach,cpc,cpm,actions',
                'date_preset' => 'last_30d',
                'level'       => 'account',
                'access_token' => $token,
            ]);

            if (!$response->successful()) {
                return ['error' => $response->json('error.message') ?? 'Insights fetch failed', 'status' => $response->status()];
            }

            $data = $response->json('data') ?? [];
            $row = $data[0] ?? [];
            return [
                'error'       => null,
                'account_id'  => $adAccountId,
                'period'      => 'Last 30 days',
                'impressions' => $row['impressions'] ?? '0',
                'clicks'      => $row['clicks'] ?? '0',
                'spend'       => $row['spend'] ?? '0.00',
                'reach'       => $row['reach'] ?? '0',
                'cpc'         => $row['cpc'] ?? null,
                'cpm'         => $row['cpm'] ?? null,
                'actions'     => $row['actions'] ?? [],
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function fetchManagedPages(string $token): array
    {
        try {
            $response = Http::get(self::GRAPH . '/me/accounts', [
                'fields'       => 'id,name,category,fan_count,followers_count,link,access_token',
                'access_token' => $token,
            ]);

            if (!$response->successful()) {
                return ['error' => $response->json('error.message') ?? 'Pages fetch failed', 'pages' => [], 'status' => $response->status()];
            }

            $pages = $response->json('data') ?? [];
            Log::info('FacebookApiOAuth: managed pages', [
                'count'              => count($pages),
                'first_page_id'      => $pages[0]['id'] ?? null,
                'has_page_token'     => isset($pages[0]['access_token']),
                'page_token_length'  => strlen($pages[0]['access_token'] ?? ''),
            ]);
            return [
                'error' => null,
                'pages' => array_map(fn($p) => [
                    'id'         => $p['id'] ?? '',
                    'name'       => $p['name'] ?? '',
                    'category'   => $p['category'] ?? '',
                    'fans'       => $p['fan_count'] ?? 0,
                    'followers'  => $p['followers_count'] ?? 0,
                    'link'       => $p['link'] ?? null,
                    'page_token' => $p['access_token'] ?? null,
                ], $pages),
                'count' => count($pages),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'pages' => []];
        }
    }

    private function fetchPagePosts(string $token, string $pageId, string $pageName): array
    {
        try {
            $response = Http::get(self::GRAPH . '/' . $pageId . '/feed', [
                'fields'       => 'id,message,story,created_time,likes.summary(true),comments.summary(true)',
                'limit'        => 5,
                'access_token' => $token,
            ]);

            if (!$response->successful()) {
                Log::warning('FacebookApiOAuth: page feed failed', [
                    'page_id'          => $pageId,
                    'status'           => $response->status(),
                    'error_code'       => $response->json('error.code'),
                    'error_subcode'    => $response->json('error.error_subcode'),
                    'error_message'    => $response->json('error.message'),
                    'token_is_user'    => strlen($token) < 200,
                ]);
                return ['error' => $response->json('error.message') ?? 'Posts fetch failed', 'posts' => [], 'status' => $response->status()];
            }

            $posts = $response->json('data') ?? [];
            return [
                'error'     => null,
                'page_id'   => $pageId,
                'page_name' => $pageName,
                'posts'     => array_map(fn($p) => [
                    'id'           => $p['id'] ?? '',
                    'message'      => $p['message'] ?? ($p['story'] ?? ''),
                    'created_time' => $p['created_time'] ?? '',
                    'likes'        => $p['likes']['summary']['total_count'] ?? 0,
                    'comments'     => $p['comments']['summary']['total_count'] ?? 0,
                ], $posts),
                'count' => count($posts),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'posts' => []];
        }
    }

    private function fetchBusinessPages(string $token, string $businessId, string $businessName): array
    {
        try {
            $response = Http::get(self::GRAPH . '/' . $businessId . '/owned_pages', [
                'fields'       => 'id,name,category,fan_count',
                'access_token' => $token,
            ]);

            if (!$response->successful()) {
                return ['error' => $response->json('error.message') ?? 'Business pages fetch failed', 'pages' => [], 'status' => $response->status()];
            }

            $pages = $response->json('data') ?? [];
            return [
                'error'         => null,
                'business_id'   => $businessId,
                'business_name' => $businessName,
                'pages'         => array_map(fn($p) => [
                    'id'       => $p['id'] ?? '',
                    'name'     => $p['name'] ?? '',
                    'category' => $p['category'] ?? '',
                    'fans'     => $p['fan_count'] ?? 0,
                ], $pages),
                'count' => count($pages),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'pages' => []];
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

    private function fetchGrantedPermissions(string $token): array
    {
        try {
            $response = Http::get(self::GRAPH . '/me/permissions', [
                'access_token' => $token,
            ]);

            if (!$response->successful()) {
                return ['error' => $response->json('error.message') ?? 'Permissions fetch failed', 'permissions' => []];
            }

            $data = $response->json('data') ?? [];
            return [
                'error'       => null,
                'permissions' => array_map(fn($p) => [
                    'permission' => $p['permission'] ?? '',
                    'status'     => $p['status'] ?? '',
                ], $data),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'permissions' => []];
        }
    }
}
