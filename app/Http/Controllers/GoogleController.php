<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Services\GoogleAds\ListAccessibleCustomers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')
            ->scopes([
                'openid',
                'profile', 
                'email',
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
        try {
            $googleUser = Socialite::driver('google')->user();

            $user = User::updateOrCreate([
                'email' => $googleUser->getEmail(),
            ], [
                'name' => $googleUser->getName(),
                'google_id' => $googleUser->getId(),
                'password' => bcrypt(str()->random(16)) // Set a random password
            ]);

            // Create a new customer for the user
            $customer = Customer::create(['name' => $user->name]);

            // Associate the customer with the user
            $user->customer_id = $customer->id;
            $user->save();

            // Store the refresh token and discover their Google Ads account
            if (isset($googleUser->refreshToken)) {
                $customer->google_ads_refresh_token = Crypt::encryptString($googleUser->refreshToken);
                $customer->save();

                // Discover the user's Google Ads customer ID from their OAuth token
                try {
                    $listService = new ListAccessibleCustomers($customer);
                    $accessibleAccounts = ($listService)();

                    if (!empty($accessibleAccounts)) {
                        // Extract customer ID from resource name (e.g., 'customers/1234567890' -> '1234567890')
                        $resourceName = $accessibleAccounts[0];
                        preg_match('/customers\/(\d+)/', $resourceName, $matches);
                        $googleAdsCustomerId = $matches[1] ?? null;

                        if ($googleAdsCustomerId) {
                            $customer->google_ads_customer_id = $googleAdsCustomerId;
                            $customer->save();

                            Log::info('Google Ads account discovered via OAuth', [
                                'customer_id' => $customer->id,
                                'google_ads_customer_id' => $googleAdsCustomerId,
                                'total_accessible_accounts' => count($accessibleAccounts),
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Could not discover Google Ads account during OAuth: ' . $e->getMessage(), [
                        'customer_id' => $customer->id,
                    ]);
                    // Non-fatal — the user can connect their account later
                }
            }

            Auth::login($user);

            return redirect('/dashboard');

        } catch (\Exception $e) {
            // Log the error
            \Log::error('Google OAuth Error: ' . $e->getMessage());
            return redirect('/login')->with('error', 'Something went wrong with the Google login.');
        }
    }
}
