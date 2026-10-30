<?php

namespace App\Http\Controllers;

use App\Models\Connection;
use App\Models\User;
use App\Services\GTM\GTMPlatformCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;

/** Admin-only authorization of the single platform GTM identity; never customer Ads OAuth. */
class GTMPlatformAuthorizationController extends Controller
{
    public const SESSION_KEY = 'gtm_platform_authorization';

    public const SCOPES = GTMPlatformCredential::SCOPES;

    public function start(Request $request)
    {
        abort_unless($request->user()?->fresh()?->isFullAdmin(), 403);
        $request->session()->forget(self::SESSION_KEY);
        $account = (string) config('services.gtm.platform_account_id');
        if (! ctype_digit($account)) {
            return back()->withErrors(['oauth' => 'Configure the platform Tag Manager account ID first.']);
        }
        $provider = Socialite::driver('google');
        abort_unless($provider instanceof GoogleProvider, 503);
        $redirect = $provider->redirectUrl(route('google-api.callback'))
            ->setScopes(self::SCOPES)->with(['access_type' => 'offline', 'prompt' => 'select_account consent'])->redirect();
        $state = $request->session()->get('state');
        if (! is_string($state) || $state === '') {
            return back()->withErrors(['oauth' => 'Platform tracking authorization could not start. Please try again.']);
        }
        $request->session()->put(self::SESSION_KEY, ['user_id' => $request->user()->id,
            'account_id' => $account, 'expires_at' => now()->addMinutes(10)->timestamp,
            'state_hash' => hash('sha256', $state)]);

        return Inertia::location($redirect->getTargetUrl());
    }

    public function complete(Request $request)
    {
        $intent = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');
        // A callback is a GET, but installs a platform credential. Support's
        // read-only admin access must never authorize this mutation.
        if (! $request->user()?->fresh()?->isFullAdmin()) {
            $request->session()->forget('state');
            abort(403);
        }
        if (! is_array($intent) || ($intent['user_id'] ?? null) !== $request->user()->id
            || ($intent['account_id'] ?? null) !== (string) config('services.gtm.platform_account_id')
            || ! is_int($intent['expires_at'] ?? null) || $intent['expires_at'] <= now()->timestamp || ! is_string($state)
            || ! is_string($intent['state_hash'] ?? null) || ! hash_equals($intent['state_hash'], hash('sha256', $state))) {
            $request->session()->forget('state');

            return redirect()->route('google-api.show')->withErrors(['oauth' => 'The platform tracking authorization expired. Start it again.']);
        }
        try {
            $provider = Socialite::driver('google');
            if (! $provider instanceof GoogleProvider) {
                throw new \RuntimeException('Google platform identity verification is unavailable.');
            }
            $googleUser = $provider->redirectUrl(route('google-api.callback'))->user();
            if (! $googleUser instanceof GoogleUser) {
                throw new \RuntimeException('Google did not return a verified platform identity.');
            }
            $raw = $googleUser->getRaw();
            $email = $googleUser->getEmail();
            if (($raw['email_verified'] ?? null) !== true || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)
                || ! is_string($raw['sub'] ?? null) || $raw['sub'] === '' || $raw['sub'] !== $googleUser->getId()
                || ($raw['email'] ?? null) !== $email
                || empty($googleUser->refreshToken) || empty($googleUser->token)) {
                throw new \RuntimeException('Google must return a verified email and offline tracking authorization.');
            }
            // Check actual granted scopes and identity, not just the requested scope list.
            $tokenInfo = Http::timeout(20)->get('https://oauth2.googleapis.com/tokeninfo', ['access_token' => $googleUser->token]);
            $granted = preg_split('/\s+/', trim((string) $tokenInfo->json('scope', ''))) ?: [];
            $required = array_filter(self::SCOPES, fn ($scope) => str_starts_with($scope, 'https://'));
            if (! $tokenInfo->successful() || $tokenInfo->json('aud') !== config('services.google.client_id')
                || strcasecmp((string) $tokenInfo->json('email'), $email) !== 0 || array_diff($required, $granted)) {
                throw new \RuntimeException('Approve all requested Tag Manager permissions so ownership and publishing can be verified.');
            }
            $account = $intent['account_id'];
            $base = 'https://tagmanager.googleapis.com/tagmanager/v2/accounts/'.$account;
            $response = Http::withToken($googleUser->token)->timeout(20)->get($base);
            if (! $response->successful() || (string) $response->json('accountId') !== $account) {
                throw new \RuntimeException('Choose a Google administrator of the existing platform Tag Manager account '.$account.'.');
            }
            $owner = false;
            $page = '';
            for ($count = 0; $count < 20; $count++) {
                $permissions = Http::withToken($googleUser->token)->timeout(20)->get($base.'/user_permissions', $page ? ['pageToken' => $page] : []);
                if (! $permissions->successful()) {
                    throw new \RuntimeException('Google did not allow checking administrator permissions for the platform Tag Manager account.');
                }
                foreach ($permissions->json('userPermission', []) as $permission) {
                    if (strcasecmp($permission['emailAddress'] ?? '', $email) === 0
                        && (string) ($permission['accountId'] ?? '') === $account && ($permission['accountAccess']['permission'] ?? '') === 'admin') {
                        $owner = true;
                    }
                }
                $page = $permissions->json('nextPageToken', '');
                if ($page === '') {
                    break;
                }
            }
            if (! $owner) {
                throw new \RuntimeException('The selected Google login must administer the existing platform Tag Manager account.');
            }
            Cache::lock('gtm-platform-credential', 30)->block(5, function () use ($request, $googleUser, $email, $granted, $account) {
                $currentAdmin = User::find($request->user()->id);
                if (! $currentAdmin || ! $currentAdmin->isFullAdmin() || $account !== (string) config('services.gtm.platform_account_id')) {
                    throw new \RuntimeException('Platform administration changed during authorization. Start again.');
                }
                Connection::updateOrCreate(['platform' => 'gtm_platform'], [
                    'user_id' => $request->user()->id, 'account_id' => $account, 'account_name' => strtolower($email),
                    'refresh_token' => $googleUser->refreshToken, 'access_token' => $googleUser->token,
                    'expires_at' => now()->addSeconds($googleUser->expiresIn), 'scopes' => $granted,
                ]);
            });

            return redirect()->route('google-api.show')->with('success', 'Platform tracking authorization is ready. Customer ownership checks can now run. Google Ads credentials were not changed.');
        } catch (\Throwable $e) {
            // Avoid reporting OAuth exception requests: they may contain tokens.
            report(new \RuntimeException('Platform GTM authorization verification failed ('.class_basename($e).').'));

            return redirect()->route('google-api.show')->withErrors(['oauth' => 'Tracking authorization could not be verified. Choose an administrator of the platform Tag Manager account and approve all requested tracking permissions. The existing credential was kept.']);
        }
    }
}
