<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeAuthController extends Controller
{
    private const SCOPE = 'https://www.googleapis.com/auth/youtube.upload';
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function redirect()
    {
        $clientId    = config('services.youtube.client_id');
        $redirectUri = route('youtube.auth.callback');

        if (!$clientId) {
            abort(500, 'GOOGLE_YOUTUBE_CLIENT_ID is not set in .env');
        }

        $params = http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent', // Force consent so we always get a refresh_token
        ]);

        return redirect(self::AUTH_URL . '?' . $params);
    }

    public function callback(Request $request)
    {
        Log::info('YouTubeAuthController: Callback received', $request->all());

        if ($request->has('error')) {
            $error       = $request->get('error');
            $description = $request->get('error_description', '');
            Log::error('YouTubeAuthController: Google returned error', [
                'error'       => $error,
                'description' => $description,
            ]);
            $hint = $error === 'redirect_uri_mismatch'
                ? ' — add https://sitetospend.com/youtube/auth/callback as an Authorized Redirect URI in Google Cloud Console → Credentials.'
                : " ({$description})";
            abort(400, "Google OAuth error: {$error}{$hint}");
        }

        $code = $request->get('code');

        if (!$code) {
            Log::error('YouTubeAuthController: No code in callback', $request->all());
            abort(400, 'No authorization code received. Visit /youtube/auth to start the flow.');
        }

        Log::info('YouTubeAuthController: Got authorization code, exchanging for tokens');

        $clientId     = config('services.youtube.client_id');
        $clientSecret = config('services.youtube.client_secret');
        $redirectUri  = route('youtube.auth.callback');

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if (!$response->successful()) {
            Log::error('YouTubeAuthController: Token exchange failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            abort(500, 'Token exchange failed: ' . $response->body());
        }

        $data         = $response->json();
        $refreshToken = $data['refresh_token'] ?? null;

        if (!$refreshToken) {
            abort(500, 'No refresh_token in response — try visiting /youtube/auth again to force re-consent.');
        }

        // Write the token to .env automatically
        $this->writeToEnv('GOOGLE_YOUTUBE_REFRESH_TOKEN', $refreshToken);

        Log::info('YouTubeAuthController: Refresh token saved to .env');

        return view('youtube-auth-success', [
            'refresh_token' => $refreshToken,
        ]);
    }

    private function writeToEnv(string $key, string $value): void
    {
        $envPath    = base_path('.env');
        $envContent = file_get_contents($envPath);

        if (str_contains($envContent, "{$key}=")) {
            $envContent = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $envContent);
        } else {
            $envContent .= "\n{$key}={$value}\n";
        }

        file_put_contents($envPath, $envContent);
    }
}
