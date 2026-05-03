<?php

namespace App\Http\Controllers;

use App\Jobs\CrawlSitemap;
use App\Jobs\ExtractBrandGuidelines;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class QuickStartController extends Controller
{
    public function show()
    {
        $user = Auth::user();

        // If the user came from the landing page demo, skip the form and auto-process.
        if ($user->demo_url && $user->customers()->doesntExist()) {
            return $this->doProcess($user->demo_url, $user, request());
        }

        return Inertia::render('QuickStart');
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
        $host         = parse_url($url, PHP_URL_HOST) ?: $url;
        $businessName = ucfirst(str_replace('www.', '', $host));
        $scheme       = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $sitemapUrl   = "{$scheme}://{$host}/sitemap.xml";

        $timezone = $request->input('timezone', 'UTC');
        $country  = $request->input('country', 'US');

        $customer = Customer::create([
            'name'     => $businessName,
            'website'  => $url,
            'country'  => $country,
            'timezone' => $timezone,
        ]);

        $user->customers()->attach($customer->id, ['role' => 'owner']);
        session(['active_customer_id' => $customer->id]);

        CrawlSitemap::dispatch($user, $sitemapUrl, $customer->id);
        ExtractBrandGuidelines::dispatch($customer)->delay(now()->addMinutes(3));

        return redirect()->route('dashboard')->with('success', "Setting up \"{$businessName}\" — we're scanning your website now. This usually takes a few minutes.");
    }
}
