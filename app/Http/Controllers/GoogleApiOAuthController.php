<?php

namespace App\Http\Controllers;

use App\Models\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    public function disconnect()
    {
        Connection::where('user_id', Auth::id())
            ->where('platform', 'google_api')
            ->delete();

        return redirect()->route('google-api.show')
            ->with('status', 'Google API connection removed.');
    }
}
