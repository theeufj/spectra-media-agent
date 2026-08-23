<?php

namespace App\Http\Controllers;

use App\Jobs\CrawlSitemap;
use App\Models\Customer;
use App\Services\ActivityLogger;
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

            // Optional: customers who already advertise on Google. Supplying it
            // triggers a manager-link invitation instead of waiting for the
            // account-creation gate to clear. Google displays ids as
            // 123-456-7890, so dashes and spaces are accepted and stripped.
            'google_ads_customer_id' => ['nullable', 'string', 'max:20', 'regex:/^[\s\d-]+$/'],
        ]);

        $this->normaliseGoogleAdsCustomerId($validated);

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
                'name' => "{$planLabel} plan is limited to {$maxAllowed} customer account".($maxAllowed > 1 ? 's' : '').'. Upgrade your plan to add more.',
            ])->withInput();
        }

        $tenant = $request->attributes->get('tenant');
        if ($tenant && ($tenant['locked_vertical'] ?? false) && ! empty($tenant['vertical'])) {
            $validated['industry'] = $tenant['vertical'];
        }

        $customer = Customer::create($validated);

        $user->customers()->attach($customer->id, ['role' => 'owner']);

        session(['active_customer_id' => $customer->id]);

        ActivityLogger::customer('created', $customer);

        // The manual form used to create the customer and stop, so anyone who
        // arrived here rather than through the quick start began with an empty
        // knowledge base and got a first campaign written with no knowledge of
        // their business. Same scan either way now.
        $scanning = CrawlSitemap::forCustomer($customer, $user);

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Customer created successfully', 'customer' => $customer]);
        }

        return redirect()->route('dashboard')->with('success', $scanning
            ? "Created \"{$customer->name}\" — we're scanning your website now. This usually takes a few minutes."
            : 'New customer account created successfully.');
    }

    public function switch(Customer $customer)
    {
        $user = Auth::user();

        if ($user->can('switchTo', $customer)) {
            session(['active_customer_id' => $customer->id]);

            return redirect()->route('dashboard')->with('success', 'Switched to customer '.$customer->name);
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
        if (! $user->can('update', $customer)) {
            return redirect()->back()->with('error', 'You do not have permission to edit this customer.');
        }

        return Inertia::render('Customers/Edit', [
            'customer' => $customer,
        ]);
    }

    /**
     * Update the customer profile.
     */
    /**
     * Reduce a supplied Google Ads account id to its ten digits, in place.
     *
     * Google displays ids as 123-456-7890 and that is how customers copy them,
     * so dashes and spaces have to be accepted and stripped. An empty value is
     * removed entirely rather than written as null: blanking the field should
     * not clear an id that is already set.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function normaliseGoogleAdsCustomerId(array &$validated): void
    {
        if (! array_key_exists('google_ads_customer_id', $validated)) {
            return;
        }

        if (empty($validated['google_ads_customer_id'])) {
            unset($validated['google_ads_customer_id']);

            return;
        }

        $digits = preg_replace('/[^0-9]/', '', (string) $validated['google_ads_customer_id']);

        if (strlen($digits) !== 10) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'google_ads_customer_id' => 'A Google Ads account ID is 10 digits, like 123-456-7890.',
            ]);
        }

        $validated['google_ads_customer_id'] = $digits;
    }

    public function update(Request $request, Customer $customer)
    {
        $user = Auth::user();

        // Check if user has access to this customer
        if (! $user->can('update', $customer)) {
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

            // Most customers do not have this to hand at sign-up, so it has to
            // be addable later. CustomerObserver sends the link invitation the
            // moment it lands, whichever path set it.
            'google_ads_customer_id' => ['nullable', 'string', 'max:20', 'regex:/^[\s\d-]+$/'],
        ]);

        $this->normaliseGoogleAdsCustomerId($validated);

        // Repointing a customer at a different account while a link is live
        // would leave campaigns referring to an account we no longer manage.
        // Unlinking is a deliberate act and should not happen as a side effect
        // of editing a profile.
        if (
            $customer->google_ads_link_status === 'active'
            && isset($validated['google_ads_customer_id'])
            && $validated['google_ads_customer_id'] !== $customer->google_ads_customer_id
        ) {
            return redirect()->back()->withErrors([
                'google_ads_customer_id' => 'This customer is already linked to Google Ads account '
                    .$customer->google_ads_customer_id.'. Contact support to move to a different account.',
            ])->withInput();
        }

        $customer->update($validated);

        Log::info('Customer profile updated', [
            'customer_id' => $customer->id,
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Customer profile updated successfully.');
    }
}
