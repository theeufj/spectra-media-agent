<?php

namespace App\Http\Controllers;

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
        $customer = $customers->first();
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
                    'account_name' => 'Google Account (via OAuth)',
                    'account_id' => null,
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
            $customer = $user->customers()->first();
            if ($customer) {
                $customer->update(['google_ads_refresh_token' => null]);
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
}
