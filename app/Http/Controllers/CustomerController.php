<?php

namespace App\Http\Controllers;

use App\Models\Customer;
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
            'country' => 'required|string|size:2', // ISO 3166-1 alpha-2 country code
            'timezone' => 'required|string|max:255|timezone', // Valid IANA timezone
            'currency_code' => 'nullable|string|size:3|uppercase', // ISO 4217 currency code
            'website' => 'nullable|url|max:255',
            'phone' => 'nullable|string|max:20',
            'facebook_page_url' => 'nullable|string|max:500',
        ]);

        $facebookPageUrl = $validated['facebook_page_url'] ?? null;
        unset($validated['facebook_page_url']);

        if ($facebookPageUrl) {
            $parsed = Customer::parseFacebookPageUrl($facebookPageUrl);
            if ($parsed) {
                $validated['facebook_page_id'] = $parsed['page_id'];
                if ($parsed['page_name']) {
                    $validated['facebook_page_name'] = $parsed['page_name'];
                }
            }
        }

        $user = $request->user();

        // Enforce sub-account limits based on plan
        $plan = $user->resolveCurrentPlan();
        $slug = $plan?->slug ?? 'free';
        $currentCount = $user->customers()->count();

        $limits = ['free' => 1, 'starter' => 1, 'growth' => 1, 'agency' => 10];
        $maxAllowed = $limits[$slug] ?? 1;

        if ($maxAllowed !== null && $currentCount >= $maxAllowed) {
            $planLabel = ucfirst($slug);
            return redirect()->back()->withErrors([
                'name' => "{$planLabel} plan is limited to {$maxAllowed} customer account" . ($maxAllowed > 1 ? 's' : '') . ". Upgrade your plan to add more.",
            ])->withInput();
        }

        $customer = Customer::create($validated);

        $user->customers()->attach($customer->id, ['role' => 'owner']);

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
            'customer'      => $customer,
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
