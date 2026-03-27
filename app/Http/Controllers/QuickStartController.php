<?php

namespace App\Http\Controllers;

use App\Jobs\CrawlSitemap;
use App\Jobs\ExtractBrandGuidelines;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class QuickStartController extends Controller
{
    public function show()
    {
        return Inertia::render('QuickStart');
    }

    public function process(Request $request)
    {
        $validated = $request->validate([
            'website_url' => 'required|url|max:255',
        ]);

        $user = Auth::user();
        $url = $validated['website_url'];

        // Extract domain name for the customer name
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $businessName = ucfirst(str_replace('www.', '', $host));

        // Build sitemap URL (try /sitemap.xml by default)
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $sitemapUrl = "{$scheme}://{$host}/sitemap.xml";

        // Detect timezone and country from request (defaults)
        $timezone = $request->input('timezone', 'UTC');
        $country = $request->input('country', 'US');

        // Create the customer
        $customer = Customer::create([
            'name' => $businessName,
            'website' => $url,
            'country' => $country,
            'timezone' => $timezone,
        ]);

        $user->customers()->attach($customer->id, ['role' => 'owner']);
        session(['active_customer_id' => $customer->id]);

        // Dispatch crawl to populate knowledge base
        CrawlSitemap::dispatch($user, $sitemapUrl, $customer->id);

        // Dispatch brand guideline extraction (will run after crawl has data)
        ExtractBrandGuidelines::dispatch($customer)->delay(now()->addMinutes(3));

        return redirect()->route('dashboard')->with('success', "Setting up \"{$businessName}\" — we're scanning your website now. This usually takes a few minutes.");
    }
}
