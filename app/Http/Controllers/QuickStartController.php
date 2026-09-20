<?php

namespace App\Http\Controllers;

use App\Jobs\CrawlSitemap;
use App\Models\Customer;
use App\Services\ActivityLogger;
use App\Services\Onboarding\WebsiteIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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

    /**
     * The holding screen shown while the scan runs. If the work is already
     * done (revisit, back button), skip straight to the guidelines.
     */
    public function scanning(Request $request)
    {
        $customer = $request->user()->customers()->find(session('active_customer_id'));

        if (! $customer) {
            return redirect()->route('quick-start');
        }

        $baseline = $request->query('after');
        if ($customer->brandGuideline && ! $request->boolean('manual')
            && (! $baseline || $customer->brandGuideline->updated_at->toIso8601String() !== $baseline)) {
            return redirect()->route('brand-guidelines.index', ['review' => 1]);
        }

        return \Inertia\Inertia::render('QuickStart/Scanning', [
            'customerName' => $customer->name,
            'website' => $customer->website,
            'setupOnly' => $customer->service_type === 'setup_only',
            'manualEntry' => $request->boolean('manual'),
            'baselineUpdatedAt' => $baseline,
        ]);
    }

    public function saveBrief(Request $request)
    {
        $customer = $request->user()->customers()->findOrFail(session('active_customer_id'));
        $this->authorize('update', $customer);
        $data = $request->validate([
            'business_name' => 'required|string|max:255',
            'business_description' => 'required|string|min:300|max:12000',
        ]);
        // One editable source, even if a client retries a submission. Never discard crawl evidence.
        \Illuminate\Support\Facades\DB::transaction(function () use ($customer, $request, $data) {
            $customer->newQuery()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $customer->update(['name' => $data['business_name']]);
            $brief = \App\Models\KnowledgeBase::firstOrNew([
                'customer_id' => $customer->id, 'source_type' => 'text', 'original_filename' => 'onboarding-business-brief.txt', 'file_path' => null,
            ]);
            $brief->fill(['url' => $customer->website ?? '', 'user_id' => $request->user()->id, 'content' => $data['business_description']]);
            if ($brief->isDirty() || ($brief->updated_at?->lt(now()->subMinutes(10)) && ! $customer->brandGuideline?->user_verified)) {
                $brief->fill(['embedding' => null, 'embedding_model' => null]);
                $brief->updated_at = now();
                $brief->save();
                $customer->brandGuideline?->update(['user_verified' => false]);
                \App\Jobs\ExtractBrandGuidelines::dispatch($customer, force: true)->afterCommit();
            }
        });

        return redirect()->route('quick-start.scanning', array_filter(['after' => $customer->brandGuideline?->refresh()->updated_at?->toIso8601String()]));
    }

    public function process(Request $request)
    {
        $validated = $request->validate([
            'website_url' => ['required', 'url', 'max:255', new \App\Rules\SafePublicUrl],
            // The fork: ongoing management (default) or the one-time US$999
            // setup. Intent only — payment is collected at the plan step.
            'service_type' => 'nullable|in:managed,setup_only',
            'timezone' => 'nullable|timezone',
            'country' => 'nullable|string|size:2|regex:/^[A-Z]{2}$/',
        ]);

        return $this->doProcess($validated['website_url'], Auth::user(), $request);
    }

    private function doProcess(string $url, $user, Request $request): RedirectResponse
    {
        /*
           A shortener is not the customer's website, it is a redirect to it.
           This used to take the host as typed, so a signup who pasted a bit.ly
           link to her trucking brokerage had her business created as "Bit.ly"
           and a brand profile built from whatever the crawler found there. She
           left four minutes later.

           Resolving happens before anything is stored, because the URL is
           written to the customer and every later job reads it from there.
        */
        $identity = app(WebsiteIdentity::class);
        $url = $identity->resolve($url);
        $businessName = $identity->businessName($url);

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

        /*
         * One business per website, however many times Go is pressed.
         *
         * This was an unconditional create, so a second submission built a
         * second identical customer — seen live: two "Yourfirststore" rows a
         * minute apart, each with its own half-finished crawl, and the
         * knowledge base landing on whichever won. A double-click, a
         * back-button retry, or an impatient second press all did it.
         *
         * Keyed on the resolved host rather than the string typed, because
         * resolve() has already unwrapped shorteners by this point: a bit.ly
         * link and the domain behind it are the same business and must not
         * produce two.
         *
         * Returning the existing one is also the right answer for the
         * customer — they get taken to the scan already in progress rather
         * than starting a second one behind the first.
         */
        $existing = $user->customers()
            ->whereRaw('lower(website) = ?', [mb_strtolower($url)])
            ->first();

        if ($existing) {
            session(['active_customer_id' => $existing->id]);

            Log::info('Quick start reused an existing business rather than creating a second', [
                'customer_id' => $existing->id,
                'website' => $url,
            ]);

            return redirect()->route('quick-start.scanning');
        }

        $customer = Customer::create([
            'name' => $businessName,
            'website' => $url,
            'tenant_key' => $tenantKey,
            'service_type' => $request->input('service_type') === 'setup_only' ? 'setup_only' : 'managed',
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

        // Not the dashboard: the holding screen narrates the scan (reading
        // pages → building the brand profile) and lands on the brand
        // guidelines for sign-off the moment they exist.
        return redirect()->route('quick-start.scanning');
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
