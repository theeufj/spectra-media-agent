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
        Log::info('Redirecting to Facebook OAuth with config_id', ['config_id' => config('services.facebook.config_id')]);
        
        return Socialite::driver('facebook')
            ->scopes([]) // Clear scopes to rely on config_id
            ->with([
                'config_id' => config('services.facebook.config_id'),
                'scope' => null, // Explicitly remove scope parameter from URL
            ])
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

            // Fetch user's Facebook Pages
            $pages = $this->fetchPages($facebookUser->token);
            
            if (!empty($pages)) {
                // Store the first page by default (or let user choose in future enhancement)
                $firstPage = $pages[0];
                $customer->update([
                    'facebook_page_id' => $firstPage['id'],
                    'facebook_page_name' => $firstPage['name'],
                ]);
                
                Log::info('Facebook Page linked', [
                    'customer_id' => $customer->id,
                    'page_id' => $firstPage['id'],
                    'page_name' => $firstPage['name'],
                ]);
            } else {
                Log::warning('No Facebook Pages found for user', [
                    'customer_id' => $customer->id,
                ]);
            }

            Log::info('Facebook OAuth successful', [
                'user_id' => Auth::id(),
                'customer_id' => $customer->id,
                'facebook_id' => $facebookUser->id,
                'pages_count' => count($pages),
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
     * Fetch user's Facebook Pages.
     *
     * @param string $accessToken
     * @return array
     */
    private function fetchPages(string $accessToken): array
    {
        try {
            $response = \Http::get('https://graph.facebook.com/v19.0/me/accounts', [
                'access_token' => $accessToken,
                'fields' => 'id,name,access_token',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['data'] ?? [];
            }

            Log::error('Failed to fetch Facebook pages', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];

        } catch (\Exception $e) {
            Log::error('Error fetching Facebook pages: ' . $e->getMessage());
            return [];
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
                'facebook_page_id' => null,
                'facebook_page_name' => null,
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
