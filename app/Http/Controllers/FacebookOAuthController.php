<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\FacebookAds\TokenService;
use App\Services\FacebookAds\PageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Carbon\Carbon;

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

            // Exchange short-lived token for long-lived token
            $tokenService = new TokenService();
            $tokenResult = $tokenService->storeAsLongLivedToken($customer, $facebookUser->token);
            
            if (!$tokenResult['success']) {
                // Fall back to short-lived token if exchange fails
                Log::warning('Failed to exchange for long-lived token, using short-lived', [
                    'customer_id' => $customer->id,
                    'error' => $tokenResult['error'] ?? 'Unknown error',
                ]);
                
                $customer->update([
                    'facebook_ads_access_token' => Crypt::encryptString($facebookUser->token),
                    'facebook_ads_account_id' => $facebookUser->id ?? null,
                    'facebook_token_expires_at' => Carbon::now()->addHours(2), // Short-lived tokens expire in ~2 hours
                    'facebook_token_is_long_lived' => false,
                ]);
            } else {
                // Long-lived token stored successfully, just update the account ID
                $customer->update([
                    'facebook_ads_account_id' => $facebookUser->id ?? null,
                ]);
            }

            // Fetch user's Facebook Pages
            $pageService = new PageService($customer->fresh());
            $pages = $pageService->getPages();
            
            if (!empty($pages)) {
                // If only one page, auto-select it
                if (count($pages) === 1) {
                    $firstPage = $pages[0];
                    $pageService->setSelectedPage($firstPage['id'], $firstPage['name']);
                    
                    Log::info('Facebook Page auto-selected (single page)', [
                        'customer_id' => $customer->id,
                        'page_id' => $firstPage['id'],
                        'page_name' => $firstPage['name'],
                    ]);
                } else {
                    // Multiple pages - user needs to select
                    // Store pages in session for selection UI
                    session(['facebook_pages' => $pages]);
                    
                    Log::info('Multiple Facebook Pages found, user needs to select', [
                        'customer_id' => $customer->id,
                        'page_count' => count($pages),
                    ]);
                    
                    return redirect('/profile?select_facebook_page=true')
                        ->with('success', 'Facebook account connected! Please select which Page to use for ads.');
                }
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
                'long_lived_token' => $tokenResult['success'] ?? false,
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
     * List available Facebook Pages for selection.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function listPages()
    {
        try {
            $activeCustomerId = session('active_customer_id');
            if (!$activeCustomerId) {
                return response()->json(['error' => 'No active customer'], 400);
            }

            $customer = Customer::findOrFail($activeCustomerId);

            // Verify access
            if (!Auth::user()->customers->contains($customer)) {
                return response()->json(['error' => 'Access denied'], 403);
            }

            // Check if we have cached pages from OAuth
            $cachedPages = session('facebook_pages');
            if ($cachedPages) {
                session()->forget('facebook_pages');
                return response()->json([
                    'pages' => $cachedPages,
                    'selected' => $customer->facebook_page_id,
                ]);
            }

            // Otherwise fetch from API
            if (empty($customer->facebook_ads_access_token)) {
                return response()->json(['error' => 'Facebook not connected'], 400);
            }

            $pageService = new PageService($customer);
            $pages = $pageService->getPages();

            return response()->json([
                'pages' => $pages,
                'selected' => $customer->facebook_page_id,
            ]);

        } catch (\Exception $e) {
            Log::error('Error listing Facebook pages: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch pages'], 500);
        }
    }

    /**
     * Select a Facebook Page for the customer.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function selectPage(Request $request)
    {
        $request->validate([
            'page_id' => 'required|string',
            'page_name' => 'required|string',
        ]);

        try {
            $activeCustomerId = session('active_customer_id');
            if (!$activeCustomerId) {
                return response()->json(['error' => 'No active customer'], 400);
            }

            $customer = Customer::findOrFail($activeCustomerId);

            // Verify access
            if (!Auth::user()->customers->contains($customer)) {
                return response()->json(['error' => 'Access denied'], 403);
            }

            $pageService = new PageService($customer);
            $success = $pageService->setSelectedPage($request->page_id, $request->page_name);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Page selected successfully',
                ]);
            }

            return response()->json(['error' => 'Failed to select page'], 500);

        } catch (\Exception $e) {
            Log::error('Error selecting Facebook page: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to select page'], 500);
        }
    }

    /**
     * Get the Facebook token status for the customer.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function tokenStatus()
    {
        try {
            $activeCustomerId = session('active_customer_id');
            if (!$activeCustomerId) {
                return response()->json(['error' => 'No active customer'], 400);
            }

            $customer = Customer::findOrFail($activeCustomerId);

            // Verify access
            if (!Auth::user()->customers->contains($customer)) {
                return response()->json(['error' => 'Access denied'], 403);
            }

            if (empty($customer->facebook_ads_access_token)) {
                return response()->json([
                    'connected' => false,
                ]);
            }

            $tokenService = new TokenService();
            $status = $tokenService->checkTokenStatus($customer);

            return response()->json([
                'connected' => true,
                'valid' => $status['valid'],
                'expires_at' => $status['expires_at'] ?? null,
                'expires_in_days' => $status['expires_in_days'] ?? null,
                'needs_refresh' => $status['needs_refresh'],
                'is_long_lived' => $customer->facebook_token_is_long_lived ?? false,
                'page_id' => $customer->facebook_page_id,
                'page_name' => $customer->facebook_page_name,
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting token status: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to get token status'], 500);
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
                'facebook_token_expires_at' => null,
                'facebook_token_refreshed_at' => null,
                'facebook_token_is_long_lived' => false,
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
