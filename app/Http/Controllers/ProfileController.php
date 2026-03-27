<?php

namespace App\Http\Controllers;

use App\Services\GoogleAds\AccessibleAccountResolver;
use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $customers = $user->customers()->with('users')->get();
        $customer = $this->resolveCustomer($request);
        
        // Get user's connected accounts from connections table
        $connections = $user->connections()->get()->map(function ($connection) {
            return [
                'id' => $connection->id,
                'platform' => $connection->platform,
                'account_name' => $connection->account_name,
                'account_id' => $connection->account_id,
                'connected_at' => $connection->created_at->diffForHumans(),
                'expires_at' => $connection->expires_at?->diffForHumans(),
                'is_expired' => $connection->expires_at ? $connection->expires_at->isPast() : false,
                'source' => 'connection',
            ];
        })->toArray();
        
        // Also check for Google connection via customer's google_ads_refresh_token
        // This handles users who signed up with Google OAuth
        if ($customer && $customer->google_ads_refresh_token) {
            // Check if we already have a 'google' connection to avoid duplicates
            $hasGoogleConnection = collect($connections)->contains(function ($conn) {
                return in_array($conn['platform'], ['google', 'google_ads']);
            });
            
            if (!$hasGoogleConnection) {
                // Add Google as a connected account (from OAuth signup)
                $connections[] = [
                    'id' => 'google_oauth', // Special ID for OAuth-based connection
                    'platform' => 'google',
                    'account_name' => $customer->google_ads_customer_id
                        ? 'Google Ads account selected'
                        : 'Google account connected - choose an Ads account',
                    'account_id' => $customer->google_ads_customer_id,
                    'connected_at' => $customer->created_at->diffForHumans(),
                    'expires_at' => null,
                    'is_expired' => false,
                    'source' => 'customer_oauth',
                ];
            }
        }

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'customers' => $customers,
            'facebookAppId' => config('services.facebook.client_id'),
            'connections' => $connections,
        ]);
    }

    public function googleAdsAccounts(Request $request, AccessibleAccountResolver $resolver): Response|RedirectResponse
    {
        $customer = $this->resolveCustomer($request);

        if (!$customer || !$customer->google_ads_refresh_token) {
            return Redirect::route('profile.edit')->with('status', 'Connect Google before selecting an Ads account.');
        }

        $accounts = $resolver->forCustomer($customer);

        if (count($accounts) === 0) {
            return Redirect::route('profile.edit')->with('status', 'No accessible Google Ads accounts were found for this Google login.');
        }

        return Inertia::render('Profile/GoogleAdsAccounts', [
            'accounts' => $accounts,
            'selectedAccountId' => $customer->google_ads_customer_id,
            'customerName' => $customer->name,
        ]);
    }

    public function updateGoogleAdsAccount(
        Request $request,
        AccessibleAccountResolver $resolver,
        \App\Services\GoogleAds\CreateManagedAccount $createManagedAccount
    ): RedirectResponse {
        $validated = $request->validate([
            'google_ads_customer_id' => ['required', 'string'],
        ]);

        $customer = $this->resolveCustomer($request);
        $accounts = $resolver->forCustomer($customer);
        $validIds = collect($accounts)->pluck('id')->all();

        if (!in_array($validated['google_ads_customer_id'], $validIds, true)) {
            return Redirect::route('profile.google-ads.accounts')->withErrors([
                'google_ads_customer_id' => 'Select one of the accessible Google Ads accounts.',
            ]);
        }

        // Use MCCAccountManager to handle account selection
        // It will detect if it's an MCC and create a Standard account if needed
        $mccManager = new \App\Services\GoogleAds\MCCAccountManager($customer, $createManagedAccount);
        $result = $mccManager->handleAccountSelection($validated['google_ads_customer_id']);

        if (!$result) {
            return Redirect::route('profile.google-ads.accounts')->withErrors([
                'google_ads_customer_id' => 'Failed to process the selected account. Please try again.',
            ]);
        }

        $message = $result['is_new_account']
            ? "Google Ads MCC detected. A new Standard account has been created and linked."
            : "Google Ads account updated successfully.";

        return Redirect::route('profile.edit')->with('status', $message);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Disconnect a connected account.
     */
    public function disconnectAccount(Request $request, string $connectionId): RedirectResponse
    {
        $user = $request->user();
        
        // Handle special case for Google OAuth connection (stored on customer)
        if ($connectionId === 'google_oauth') {
            $customer = $this->resolveCustomer($request);
            if ($customer) {
                $customer->update([
                    'google_ads_refresh_token' => null,
                    'google_ads_customer_id' => null,
                ]);
            }
            return Redirect::route('profile.edit')->with('status', 'Google account disconnected successfully.');
        }
        
        // Handle regular connection records
        $connection = $user->connections()->findOrFail($connectionId);
        $platformName = ucfirst(str_replace('_', ' ', $connection->platform));
        
        $connection->delete();

        return Redirect::route('profile.edit')->with('status', "{$platformName} account disconnected successfully.");
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    private function resolveCustomer(Request $request)
    {
        $user = $request->user();
        $activeCustomerId = session('active_customer_id');

        return $user->customers()->find($activeCustomerId) ?? $user->customers()->first();
    }
}
