<?php

namespace App\Http\Controllers;

use App\Jobs\CrawlSitemap;
use App\Models\Customer;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class QuickStartController extends Controller
{
    public function show()
    {
        $user = Auth::user();

        // If the user came from the landing page demo, prefill their URL and
        // let the page submit itself. Processing directly here was a GET with
        // no browser timezone, so every demo signup was created as UTC/US —
        // the round-trip through the form is what carries the real locale.
        return Inertia::render('QuickStart', [
            'demoUrl' => $user->customers()->doesntExist() ? $user->demo_url : null,
        ]);
    }

    public function process(Request $request)
    {
        $validated = $request->validate([
            'website_url' => 'required|url|max:255',
        ]);

        return $this->doProcess($validated['website_url'], Auth::user(), $request);
    }

    private function doProcess(string $url, $user, Request $request): RedirectResponse
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $businessName = ucfirst(str_replace('www.', '', $host));

        $timezone = $request->input('timezone') ?: 'UTC';

        // The form only ever collected a URL and the browser timezone, but this
        // defaulted country to US regardless — so an Australian signup got
        // Australia/Sydney paired with US targeting. Derive it from the
        // timezone instead, which is the one regional signal we actually have.
        $country = $request->input('country') ?: self::countryFromTimezone($timezone);

        // The skin the customer is being created ON wins — every lifecycle
        // email brands itself from this. The live host beats the user's
        // stored key because OAuth signups can carry the wrong tenant: the
        // provider redirects to one registered callback URI, so a skin-domain
        // signup gets stamped with the canonical domain's tenant.
        $tenantKey = $request->attributes->get('tenant')['key'] ?? $user->tenant_key;
        if ($tenantKey && ! $user->tenant_key) {
            $user->forceFill(['tenant_key' => $tenantKey])->save();
        }

        $customer = Customer::create([
            'name' => $businessName,
            'website' => $url,
            'tenant_key' => $tenantKey,
            'country' => $country,
            'timezone' => $timezone,
            // Must be right at creation time: ProvisionGoogleAdsAccount bakes
            // this into the sub-account, and Google Ads currency is permanent.
            // Leaving it to the column default gave every non-US signup USD.
            'currency_code' => self::currencyForCountry($country),
        ]);

        $user->customers()->attach($customer->id, ['role' => 'owner']);
        session(['active_customer_id' => $customer->id]);

        ActivityLogger::customer('created', $customer);

        // ExtractBrandGuidelines is NOT dispatched here. The crawl chain ends
        // by dispatching it once pages actually exist — a delayed copy fired on
        // a timer used to race that, and on a healthy crawl ran the job twice.
        CrawlSitemap::forCustomer($customer, $user);

        return redirect()->route('dashboard')->with('success', "Setting up \"{$businessName}\" — we're scanning your website now. This usually takes a few minutes.");
    }

    /**
     * Best-effort country for a browser-reported IANA timezone. Falls back to
     * US, which is what this used to assume unconditionally.
     */
    private static function countryFromTimezone(string $timezone): string
    {
        try {
            $country = (new \DateTimeZone($timezone))->getLocation()['country_code'] ?? null;
        } catch (\Throwable) {
            return 'US';
        }

        // '??' is what DateTimeZone reports for a zone it cannot place.
        return $country && $country !== '??' ? $country : 'US';
    }

    /**
     * ISO 4217 currency for the countries we see sign up. Mirrors the
     * COUNTRY_CURRENCY map the manual form uses client-side
     * (resources/js/Pages/Customers/Create.jsx). Unknown countries get USD,
     * matching the column default this used to fall through to.
     */
    private static function currencyForCountry(string $country): string
    {
        return match ($country) {
            'US' => 'USD', 'CA' => 'CAD', 'GB' => 'GBP', 'AU' => 'AUD',
            'NZ' => 'NZD', 'IN' => 'INR', 'JP' => 'JPY', 'KR' => 'KRW',
            'SG' => 'SGD', 'HK' => 'HKD', 'CH' => 'CHF', 'SE' => 'SEK',
            'NO' => 'NOK', 'DK' => 'DKK', 'PL' => 'PLN', 'CZ' => 'CZK',
            'BR' => 'BRL', 'MX' => 'MXN', 'AR' => 'ARS', 'ZA' => 'ZAR',
            'AE' => 'AED', 'SA' => 'SAR',
            'IE', 'DE', 'FR', 'ES', 'IT', 'NL', 'BE', 'AT', 'FI', 'PT', 'GR' => 'EUR',
            default => 'USD',
        };
    }
}
