<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use App\Mail\WelcomeEmail;
use App\Services\GoogleAds\ListAccessibleCustomers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

    public function callback()
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

        if ($user->wasRecentlyCreated) {
            // Store the refresh token in the session to be used when creating the customer
            session(['google_ads_refresh_token' => $refreshToken]);

            Mail::to($user->email)->send(new WelcomeEmail($user->name));
        } else {
            // Update existing customer's refresh token if we got a new one
            if ($refreshToken && $user->customers()->count() > 0) {
                $customer = $user->customers()->first();
                $customer->update([
                    'google_ads_refresh_token' => $refreshToken,
                ]);

                // Discover Google Ads customer ID from the new token
                $this->discoverGoogleAdsCustomerId($customer);
            }
        }

        Auth::login($user, true);

        if ($user->customers()->doesntExist()) {
            return redirect()->route('customers.create');
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Discover and store the Google Ads customer ID for a customer.
     */
    private function discoverGoogleAdsCustomerId(Customer $customer): void
    {
        try {
            $listService = new ListAccessibleCustomers($customer);
            $accessibleAccounts = $listService();

            if (!empty($accessibleAccounts)) {
                $resourceName = $accessibleAccounts[0];
                if (preg_match('/customers\/(\d+)/', $resourceName, $matches)) {
                    $customer->update(['google_ads_customer_id' => $matches[1]]);
                    Log::info('Discovered Google Ads customer ID', [
                        'customer_id' => $customer->id,
                        'google_ads_customer_id' => $matches[1],
                        'total_accessible' => count($accessibleAccounts),
                    ]);
                }
            } else {
                Log::warning('No accessible Google Ads accounts found', [
                    'customer_id' => $customer->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to discover Google Ads customer ID', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
