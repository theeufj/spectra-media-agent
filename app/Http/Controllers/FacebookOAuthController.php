<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class FacebookOAuthController extends Controller
{
    /**
     * Redirect to Facebook OAuth login.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function redirect()
    {
        return Socialite::driver('facebook')
            ->scopes(['ads_management', 'business_management', 'email'])
            ->redirect();
    }

    /**
     * Handle the Facebook OAuth callback.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function callback()
    {
        try {
            $facebookUser = Socialite::driver('facebook')->user();

            // Get the currently active customer
            $activeCustomerId = session('active_customer_id');
            if (!$activeCustomerId) {
                Log::error('Facebook OAuth: No active customer in session');
                return redirect('/profile')->with('error', 'No active customer account. Please select a customer first.');
            }

            $customer = Customer::findOrFail($activeCustomerId);

            // Verify the customer belongs to the authenticated user
            if (!Auth::user()->customers->contains($customer)) {
                Log::error('Facebook OAuth: User does not have access to customer', [
                    'user_id' => Auth::id(),
                    'customer_id' => $customer->id,
                ]);
                return redirect('/profile')->with('error', 'You do not have access to this customer account.');
            }

            // Store the access token (encrypted)
            $customer->update([
                'facebook_ads_access_token' => Crypt::encryptString($facebookUser->token),
                'facebook_ads_account_id' => $facebookUser->id ?? null,
            ]);

            Log::info('Facebook OAuth successful', [
                'user_id' => Auth::id(),
                'customer_id' => $customer->id,
                'facebook_id' => $facebookUser->id,
            ]);

            return redirect('/profile')->with('success', 'Facebook account connected successfully!');

        } catch (\Exception $e) {
            Log::error('Facebook OAuth Error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return redirect('/profile')->with('error', 'Something went wrong connecting your Facebook account. Please try again.');
        }
    }

    /**
     * Disconnect Facebook account from customer.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function disconnect()
    {
        try {
            $activeCustomerId = session('active_customer_id');
            if (!$activeCustomerId) {
                return redirect('/profile')->with('error', 'No active customer selected.');
            }

            $customer = Customer::findOrFail($activeCustomerId);

            // Verify access
            if (!Auth::user()->customers->contains($customer)) {
                return redirect('/profile')->with('error', 'You do not have access to this customer account.');
            }

            // Clear the Facebook credentials
            $customer->update([
                'facebook_ads_account_id' => null,
                'facebook_ads_access_token' => null,
            ]);

            Log::info('Facebook account disconnected', [
                'user_id' => Auth::id(),
                'customer_id' => $customer->id,
            ]);

            return redirect('/profile')->with('success', 'Facebook account disconnected successfully.');

        } catch (\Exception $e) {
            Log::error('Error disconnecting Facebook account: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return redirect('/profile')->with('error', 'Error disconnecting Facebook account.');
        }
    }
}
