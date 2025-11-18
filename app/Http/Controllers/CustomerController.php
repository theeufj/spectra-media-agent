<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\GoogleAds\CreateAndLinkManagedAccount;
use App\Services\FacebookAds\CreateFacebookAdsAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function create()
    {
        return Inertia::render('Customers/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'business_type' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'country' => 'nullable|string|size:2', // ISO 3166-1 alpha-2 country code
            'timezone' => 'nullable|string|max:255|timezone', // Valid IANA timezone
            'currency_code' => 'nullable|string|size:3|uppercase', // ISO 4217 currency code
            'website' => 'nullable|url|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        $customer = Customer::create($validated);

        $user = $request->user();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        // Attempt to create a Google Ads managed account
        try {
            $managerCustomerId = config('googleads.mcc_customer_id');
            
            if ($managerCustomerId) {
                $createAndLink = app(CreateAndLinkManagedAccount::class);
                $result = $createAndLink(
                    $managerCustomerId,
                    $customer->name,
                    $validated['currency_code'] ?? 'USD',
                    $validated['timezone'] ?? 'America/New_York'
                );

                if ($result) {
                    // Update customer with Google Ads customer ID
                    $customer->update([
                        'google_ads_customer_id' => $result['customer_id']
                    ]);
                    Log::info("Google Ads managed account created for customer", [
                        'customer_id' => $customer->id,
                        'google_ads_customer_id' => $result['customer_id'],
                    ]);
                } else {
                    Log::warning("Failed to create Google Ads managed account for customer", [
                        'customer_id' => $customer->id,
                        'customer_name' => $customer->name,
                    ]);
                }
            } else {
                Log::warning("MCC customer ID not configured, skipping Google Ads account creation", [
                    'customer_id' => $customer->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Error creating Google Ads managed account: " . $e->getMessage(), [
                'exception' => $e,
                'customer_id' => $customer->id,
            ]);
            // Don't fail the customer creation if Google Ads account creation fails
        }

        // Attempt to create a Facebook Ads account
        try {
            $createFacebookAccount = new CreateFacebookAdsAccount($customer);
            $facebookResult = $createFacebookAccount->getOrCreate(
                $customer->name,
                $validated['currency_code'] ?? 'USD',
                $validated['timezone'] ?? 'America/New_York'
            );

            if ($facebookResult && isset($facebookResult['account_id'])) {
                // Update customer with Facebook Ads account ID
                $customer->update([
                    'facebook_ads_account_id' => $facebookResult['account_id']
                ]);
                Log::info("Facebook Ads account linked for customer", [
                    'customer_id' => $customer->id,
                    'facebook_ads_account_id' => $facebookResult['account_id'],
                ]);
            } else {
                Log::warning("Failed to create or link Facebook Ads account for customer", [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Error creating Facebook Ads account: " . $e->getMessage(), [
                'exception' => $e,
                'customer_id' => $customer->id,
            ]);
            // Don't fail the customer creation if Facebook Ads account creation fails
        }

        session(['active_customer_id' => $customer->id]);

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Customer created successfully', 'customer' => $customer]);
        }

        return redirect()->route('dashboard')->with('success', 'New customer account created successfully.');
    }

    public function switch(Customer $customer)
    {
        $user = Auth::user();

        if ($user->customers->contains($customer)) {
            session(['active_customer_id' => $customer->id]);
            return redirect()->route('dashboard')->with('success', 'Switched to customer ' . $customer->name);
        }

        return redirect()->back()->with('error', 'You do not have permission to access this customer.');
    }

    /**
     * Show the form for editing the customer profile.
     */
    public function edit(Customer $customer)
    {
        $user = Auth::user();

        // Check if user has access to this customer
        if (!$user->customers->contains($customer)) {
            return redirect()->back()->with('error', 'You do not have permission to edit this customer.');
        }

        return Inertia::render('Customers/Edit', [
            'customer' => $customer,
        ]);
    }

    /**
     * Update the customer profile.
     */
    public function update(Request $request, Customer $customer)
    {
        $user = Auth::user();

        // Check if user has access to this customer
        if (!$user->customers->contains($customer)) {
            return redirect()->back()->with('error', 'You do not have permission to update this customer.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'business_type' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'country' => 'nullable|string|size:2', // ISO 3166-1 alpha-2 country code
            'timezone' => 'nullable|string|max:255|timezone', // Valid IANA timezone
            'currency_code' => 'nullable|string|size:3|uppercase', // ISO 4217 currency code
            'website' => 'nullable|url|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        $customer->update($validated);

        Log::info('Customer profile updated', [
            'customer_id' => $customer->id,
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Customer profile updated successfully.');
    }
}
