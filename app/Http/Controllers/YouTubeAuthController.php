<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One-time OAuth flow for the single YouTube channel every customer ad video
 * is uploaded to. The token it writes is a platform credential, not a
 * customer one — both routes are therefore admin-only, and the callback
 * carries a state nonce so the code it exchanges can only be one an admin
 * started the flow for. It used to sit outside the auth middleware, which
 * meant anyone could hand it a code minted for their own Google account and
 * redirect every customer's video uploads to their channel.
 */
class YouTubeAuthController extends Controller
{
    private const SCOPE = 'https://www.googleapis.com/auth/youtube.upload';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Session key holding the state nonce between the redirect and the callback. */
    private const STATE_KEY = 'youtube_oauth_state';

    public function redirect(Request $request)
    {
        $this->requireFullAdmin($request);

        $clientId = config('services.youtube.client_id');
        $redirectUri = 'https://sitetospend.com/youtube/auth/callback';

        if (! $clientId) {
            abort(500, 'GOOGLE_YOUTUBE_CLIENT_ID is not set in .env');
        }

        // Google echoes this back on the callback. Anything that arrives
        // without the value this session just issued did not come from a flow
        // this admin started, so it never reaches the token exchange.
        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        Log::info('YouTubeAuthController: Redirecting to Google', ['redirect_uri' => $redirectUri]);

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent', // Force consent so we always get a refresh_token
            'state' => $state,
        ]);

        return redirect(self::AUTH_URL.'?'.$params);
    }

    public function callback(Request $request)
    {
        $this->requireFullAdmin($request);

        // Deliberately no full_url and no $request->all(): the authorization
        // code is a bearer credential and the log is not the place for it.
        Log::info('YouTubeAuthController: Callback received');

        if ($request->has('error')) {
            $error = $request->get('error');
            $description = $request->get('error_description', '');
            Log::error('YouTubeAuthController: Google returned error', [
                'error' => $error,
                'description' => $description,
            ]);
            $hint = $error === 'redirect_uri_mismatch'
                ? ' — add https://sitetospend.com/youtube/auth/callback as an Authorized Redirect URI in Google Cloud Console → Credentials.'
                : " ({$description})";
            abort(400, "Google OAuth error: {$error}{$hint}");
        }

        $expectedState = $request->session()->pull(self::STATE_KEY);
        $state = (string) $request->get('state');

        if (! is_string($expectedState) || $expectedState === '' || ! hash_equals($expectedState, $state)) {
            Log::warning('YouTubeAuthController: State did not match the one this session issued');
            abort(403, 'OAuth state mismatch — start the flow again at /youtube/auth.');
        }

        $code = $request->get('code');

        if (! $code) {
            Log::error('YouTubeAuthController: No code in callback');
            abort(400, 'No authorization code received. Visit /youtube/auth to start the flow.');
        }

        Log::info('YouTubeAuthController: Got authorization code, exchanging for tokens');

        $clientId = config('services.youtube.client_id');
        $clientSecret = config('services.youtube.client_secret');
        $redirectUri = 'https://sitetospend.com/youtube/auth/callback';

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            Log::error('YouTubeAuthController: Token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            abort(500, 'Token exchange failed: '.$response->body());
        }

        $data = $response->json();
        $refreshToken = $data['refresh_token'] ?? null;

        if (! $refreshToken) {
            abort(500, 'No refresh_token in response — try visiting /youtube/auth again to force re-consent.');
        }

        // Write the token to .env automatically
        $this->writeToEnv('GOOGLE_YOUTUBE_REFRESH_TOKEN', $refreshToken);

        Log::info('YouTubeAuthController: Refresh token saved to .env');

        // Fingerprint only. The page is rendered to a browser and lands in
        // history, screenshots and screen shares; the token itself is already
        // where it needs to be, and the last four characters are enough to
        // tell one token from another.
        return view('youtube-auth-success', [
            'refresh_token' => str_repeat('•', 8).substr($refreshToken, -4),
        ]);
    }

    /**
     * AdminMiddleware waves GETs through for support staff, on the rule that a
     * read is safe. Neither of these is a read: one starts the flow that
     * replaces the platform's YouTube credential, the other completes it.
     */
    private function requireFullAdmin(Request $request): void
    {
        abort_unless($request->user()?->isFullAdmin() === true, 403, 'This action requires a full admin account.');
    }

    private function writeToEnv(string $key, string $value): void
    {
        $envPath = base_path('.env');
        $envContent = file_get_contents($envPath);

        if (str_contains($envContent, "{$key}=")) {
            $envContent = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $envContent);
        } else {
            $envContent .= "\n{$key}={$value}\n";
        }

        file_put_contents($envPath, $envContent);
    }
}
