<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeEmail;
use App\Models\Customer;
use App\Models\User;
use App\Services\GoogleAds\AccessibleAccountResolver;
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
                'https://www.googleapis.com/auth/adwords', // Google Ads API - Full access to ads management
                'https://www.googleapis.com/auth/tagmanager.edit.containers', // GTM - Edit containers
                'https://www.googleapis.com/auth/tagmanager.edit.containerversions', // GTM - Edit container versions
                'https://www.googleapis.com/auth/tagmanager.publish', // GTM - Publish containers
            ])
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    public function callback(AccessibleAccountResolver $resolver)
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::firstOrCreate([
            'email' => $googleUser->getEmail(),
        ], [
            'name' => $googleUser->getName(),
            'password' => Hash::make(Str::random(24)),
        ]);

        // Always mark email as verified when signing in via Google (Google has verified it)
        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
        }

        // Store the Google OAuth refresh token for API access
        $refreshToken = $googleUser->refreshToken;

        Auth::login($user, true);

        if ($user->wasRecentlyCreated) {
            // Store the refresh token in the session to be used when creating the customer
            session(['google_ads_refresh_token' => $refreshToken]);

            Mail::to($user->email)->send(new WelcomeEmail($user->name));
        } else {
            // Update existing customer's refresh token if we got a new one
            if ($refreshToken && $user->customers()->count() > 0) {
                $customer = $this->resolveCustomer($user);
                $customer->update([
                    'google_ads_refresh_token' => $refreshToken,
                ]);

                $accounts = $resolver->forCustomer($customer);

                if (count($accounts) === 1) {
                    $customer->update(['google_ads_customer_id' => $accounts[0]['id']]);

                    return redirect()->route('profile.edit')->with('status', 'Google Ads account connected successfully.');
                }

                if (count($accounts) > 1) {
                    return redirect()->route('profile.google-ads.accounts')->with('status', 'Select the Google Ads account you want Spectra to use.');
                }
            }
        }

        if ($user->customers()->doesntExist()) {
            return redirect()->route('customers.create');
        }

        return redirect()->intended(route('dashboard'));
    }

    private function resolveCustomer(User $user): Customer
    {
        $activeCustomerId = session('active_customer_id');

        return $user->customers()->find($activeCustomerId) ?? $user->customers()->firstOrFail();
    }
}
