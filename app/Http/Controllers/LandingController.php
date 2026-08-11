<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    public function index(Request $request)
    {
        $plans = Plan::active()->ordered()->where('is_free', false)->get();
        $tenant = $request->attributes->get('tenant', config('tenants.'.config('tenants.default')));

        $page = ($tenant['key'] ?? '') === 'realpropertyads' ? 'RealEstateLanding' : 'Landing';

        return \Inertia\Inertia::render($page, [
            'plans' => $plans,
            'meta' => $this->meta(
                'Automated Google Ads Management | sitetospend',
                'Launch and optimise Google and Meta ad campaigns automatically. AI agents handle keywords, bids, budgets and conversion tracking for you.'
            ),
        ]);
    }

    public function features()
    {
        return \Inertia\Inertia::render('Features', [
            'meta' => $this->meta(
                'Features — Automated Campaign Management | sitetospend',
                'Keyword research, bid management, budget pacing, conversion tracking and creative testing, run automatically across Google and Meta Ads.'
            ),
        ]);
    }

    public function howItWorks(Request $request)
    {
        $tenant = $request->attributes->get('tenant', config('tenants.'.config('tenants.default')));

        $page = ($tenant['key'] ?? '') === 'realpropertyads' ? 'RealEstateHowItWorks' : 'HowItWorks';

        return \Inertia\Inertia::render($page, [
            'meta' => $this->meta(
                'How It Works — Ads Live in Minutes | sitetospend',
                'Connect your site, and campaigns are built, launched and optimised for you. See how automated Google Ads management works, step by step.'
            ),
        ]);
    }

    public function pricing(Request $request)
    {
        $tenant = $request->attributes->get('tenant', config('tenants.'.config('tenants.default')));

        if (($tenant['key'] ?? '') === 'realpropertyads') {
            return \Inertia\Inertia::render('RealEstatePricing');
        }

        $plans = Plan::active()->ordered()->where('is_free', false)->get();

        return \Inertia\Inertia::render('Pricing', [
            'plans' => $plans,
            'meta' => $this->meta(
                'Pricing — Google Ads Management from A$250/mo | sitetospend',
                'Flat monthly pricing for automated Google and Meta ads management. No percentage of ad spend, no lock-in contract. See plans and start free.'
            ),
        ]);
    }

    public function about()
    {
        return \Inertia\Inertia::render('About', [
            'meta' => $this->meta(
                'About sitetospend — Ads Management Without an Agency',
                'We built the ad agency we wanted to hire: AI agents that run Google and Meta campaigns properly, at a fraction of agency cost.'
            ),
        ]);
    }

    /**
     * Server-rendered metadata for a public page.
     *
     * Descriptions are written to be read in a search result — under 155 characters so
     * Google does not truncate them, leading with the outcome rather than the
     * product category, and using the words people actually search for.
     *
     * @return array<string, string>
     */
    private function meta(string $title, string $description): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'canonical' => str_replace('http://', 'https://', url()->current()),
        ];
    }
}
