<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\GoogleAds\AccessibleAccountResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function create()
    {
        return Inertia::render('Customers/Create');
    }

    public function store(Request $request, AccessibleAccountResolver $resolver)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'business_type' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'country' => 'required|string|size:2', // ISO 3166-1 alpha-2 country code
            'timezone' => 'required|string|max:255|timezone', // Valid IANA timezone
            'currency_code' => 'nullable|string|size:3|uppercase', // ISO 4217 currency code
            'website' => 'nullable|url|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        $customer = Customer::create($validated);

        $user = $request->user();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        // Apply Google Ads refresh token from OAuth session
        $refreshToken = session('google_ads_refresh_token');
        if ($refreshToken) {
            $customer->update(['google_ads_refresh_token' => $refreshToken]);
            session()->forget('google_ads_refresh_token');

            $accounts = $resolver->forCustomer($customer);

            if (count($accounts) === 1) {
                $customer->update(['google_ads_customer_id' => $accounts[0]['id']]);
            }
        }

        session(['active_customer_id' => $customer->id]);

        if ($refreshToken && isset($accounts) && count($accounts) > 1) {
            return redirect()->route('profile.google-ads.accounts')->with('status', 'Select the Google Ads account you want Spectra to use.');
        }

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
