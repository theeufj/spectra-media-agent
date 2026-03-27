<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditSession;
use App\Models\Customer;
use App\Models\User;
use App\Jobs\RunAccountAudit;
use App\Services\GoogleAds\ListAccessibleCustomers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    /**
     * Redirect to Google OAuth with read-only scopes for the free audit.
     */
    public function redirectForAudit()
    {
        return Socialite::driver('google')
            ->scopes([
                'openid',
                'profile',
                'email',
                'https://www.googleapis.com/auth/adwords.readonly',
            ])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
                'state' => 'audit',
            ])
            ->redirect();
    }

    /**
     * Handle the Google OAuth callback for audit sessions.
     */
    public function callbackForAudit()
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            $token = Str::random(64);

            $auditSession = AuditSession::create([
                'token' => $token,
                'email' => $googleUser->getEmail(),
                'platform' => 'google',
                'access_token_encrypted' => Crypt::encryptString($googleUser->token),
                'refresh_token_encrypted' => isset($googleUser->refreshToken)
                    ? Crypt::encryptString($googleUser->refreshToken)
                    : null,
                'status' => 'pending',
            ]);

            // Discover accessible Google Ads accounts using the audit token
            try {
                $tempCustomer = new Customer();
                $tempCustomer->google_ads_refresh_token = $auditSession->refresh_token_encrypted;

                $listService = new ListAccessibleCustomers($tempCustomer);
                $accessibleAccounts = ($listService)();

                if (!empty($accessibleAccounts)) {
                    preg_match('/customers\/(\d+)/', $accessibleAccounts[0], $matches);
                    $googleAdsCustomerId = $matches[1] ?? null;

                    if ($googleAdsCustomerId) {
                        $auditSession->update(['google_ads_customer_id' => $googleAdsCustomerId]);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Could not discover Google Ads account during audit OAuth', [
                    'error' => $e->getMessage(),
                ]);
            }

            if (!$auditSession->google_ads_customer_id) {
                $auditSession->update(['status' => 'failed']);
                return redirect('/free-audit')->with('error', 'No Google Ads account found. Please ensure your Google account has an active Google Ads account.');
            }

            // Dispatch the audit job
            RunAccountAudit::dispatch($auditSession);

            return redirect("/free-audit/{$token}");

        } catch (\Exception $e) {
            Log::error('Google Audit OAuth Error: ' . $e->getMessage());
            return redirect('/free-audit')->with('error', 'Something went wrong connecting your Google account. Please try again.');
        }
    }
}
