<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use App\Mail\WelcomeEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')
            ->scopes([
                'https://www.googleapis.com/auth/adwords', // Google Ads API
                'https://www.googleapis.com/auth/tagmanager.edit.containers', // GTM - Edit containers
                'https://www.googleapis.com/auth/tagmanager.publish', // GTM - Publish containers
                'https://www.googleapis.com/auth/tagmanager.readonly', // GTM - Read containers
            ])
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    public function callback()
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::updateOrCreate([
            'email' => $googleUser->getEmail(),
        ], [
            'name' => $googleUser->getName(),
            'password' => Hash::make(Str::random(24)),
        ]);

        // Store the Google OAuth refresh token for API access
        $refreshToken = $googleUser->refreshToken;
        
        if ($user->wasRecentlyCreated) {
            $customer = Customer::create([
                'google_ads_refresh_token' => $refreshToken, // Used for both Google Ads and GTM APIs
            ]);
            $user->customers()->attach($customer->id, ['role' => 'owner']);

            Mail::to($user->email)->send(new WelcomeEmail($user->name));
        } else {
            // Update existing customer's refresh token if we got a new one
            if ($refreshToken && $user->customers()->count() > 0) {
                $customer = $user->customers()->first();
                $customer->update([
                    'google_ads_refresh_token' => $refreshToken,
                ]);
            }
        }

        Auth::login($user, true);

        return redirect()->route('dashboard');
    }
}
