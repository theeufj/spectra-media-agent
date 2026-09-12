<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RendersPageMeta;
use App\Models\Plan;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    use RendersPageMeta;

    public function index(Request $request)
    {
        $plans = Plan::active()->ordered()->where('is_free', false)->get();
        $tenant = $request->attributes->get('tenant', config('tenants.'.config('tenants.default')));

        $isRealEstate = ($tenant['key'] ?? '') === 'realpropertyads';
        $page = $isRealEstate ? 'RealEstateLanding' : 'Landing';

        $setupFeeUsd = $this->setupFeeUsd();
        $faqs = $this->faqs('landing', $setupFeeUsd);

        // The vertical skin renders a different component with different copy,
        // so it needs its own metadata. It used to inherit this method's, which
        // meant realpropertyads.com served "…| sitetospend" in its <title> and
        // Open Graph tags to every crawler that does not run JavaScript.
        if ($isRealEstate) {
            return \Inertia\Inertia::render($page, [
                'plans' => $plans,
                'meta' => $this->meta(
                    'Real Property Ads — Google Ads Built for Real Estate Agents',
                    'Get more listings and sell faster with AI-built Google Ads campaigns made for real estate agents. Launch your first property campaign in minutes.',
                    'Real Property Ads — More Listings. More Closings.',
                    'Stop paying for generic ads. Real Property Ads builds a Google campaign tailored to each property listing, automatically.',
                ),
            ]);
        }
        $paidPrices = $plans->pluck('price_cents')->filter()->map(fn ($cents) => (int) round($cents / 100));

        return \Inertia\Inertia::render($page, [
            'plans' => $plans,
            'faqs' => $faqs,
            'setupFeeUsd' => $setupFeeUsd,
            'meta' => $this->meta(
                'AI Google & Meta Ads Automation Software | sitetospend',
                'Automate your Google and Meta PPC campaigns with AI. Let intelligent agents handle keyword research, bidding, budgets, and tracking to maximize your ROI.',
                'sitetospend — Your AI Marketing Team',
                'Ads that run themselves. Agency-level results without the agency retainer. No credit card required.',
                // The offer range is read off the plans actually on sale rather
                // than the "149" and "249" that used to be written into the JSX,
                // where a price change in the database left the rich result
                // advertising a number the pricing page no longer showed.
                [
                    [
                        '@type' => 'Organization',
                        'name' => 'sitetospend',
                        'url' => 'https://sitetospend.com',
                        'logo' => 'https://sitetospend.com/og-image.png',
                        'description' => 'AI-powered digital advertising platform with autonomous agents that manage and optimize ad campaigns across Google, Facebook, Microsoft, and LinkedIn.',
                        'sameAs' => ['https://www.linkedin.com/company/sitetospend'],
                    ],
                    [
                        '@type' => 'SoftwareApplication',
                        'name' => 'sitetospend',
                        'applicationCategory' => 'BusinessApplication',
                        'operatingSystem' => 'Web',
                        'url' => 'https://sitetospend.com',
                        'description' => 'Autonomous AI agents that create, manage, and optimize digital ad campaigns across Google Ads, Facebook Ads, Microsoft Ads, and LinkedIn Ads.',
                        'offers' => [
                            '@type' => 'AggregateOffer',
                            'lowPrice' => $paidPrices->min() ?? 149,
                            // The one-time setup sits above every monthly plan,
                            // so it sets the ceiling of the range rather than
                            // being left out of it.
                            'highPrice' => max($paidPrices->max() ?? 249, $setupFeeUsd),
                            'priceCurrency' => 'USD',
                            'offerCount' => $paidPrices->count() + 1,
                        ],
                        'featureList' => [
                            'AI Competitor Discovery',
                            'Self-Optimising Campaigns',
                            'Budget Intelligence',
                            'Creative A/B Testing',
                            'Audience Intelligence',
                            'Vision AI Brand Extraction',
                        ],
                    ],
                    $this->faqSchema($faqs),
                ],
            ),
        ]);
    }

    public function features()
    {
        return \Inertia\Inertia::render('Features', [
            'meta' => $this->meta(
                'Features — Automated Campaign Management | sitetospend',
                'Keyword research, bid management, budget pacing, conversion tracking and creative testing, run automatically across Google and Meta Ads.',
                'Features — 6 Autonomous AI Marketing Agents | sitetospend',
                'Competitor discovery, self-optimising campaigns, budget intelligence, creative testing, audience management and Vision AI brand extraction — all on autopilot.',
                [[
                    '@type' => 'ItemList',
                    'name' => 'sitetospend AI marketing features',
                    'description' => 'Six autonomous AI agents and a full campaign management suite for Google, Meta, Microsoft and LinkedIn Ads.',
                    'numberOfItems' => 6,
                    'itemListElement' => $this->listItems([
                        ['Competitor Discovery Agent', 'Uses Google Search AI to find real competitors based on your website content.'],
                        ['Competitor Analysis Agent', 'Reads competitor websites, extracts messaging and pricing, generates counter-strategies.'],
                        ['Self-Optimising Agent', 'Detects and fixes disapproved ads automatically while keeping your brand voice.'],
                        ['Budget Intelligence Agent', 'Adjusts budgets by time-of-day and day-of-week performance.'],
                        ['Creative Intelligence Agent', 'Tracks A/B results, keeps winners, and writes new variations to replace losers.'],
                        ['Audience Intelligence Agent', 'Manages Customer Match lists, segments audiences, recommends lookalike expansion.'],
                    ]),
                ]],
            ),
        ]);
    }

    public function howItWorks(Request $request)
    {
        $tenant = $request->attributes->get('tenant', config('tenants.'.config('tenants.default')));

        if (($tenant['key'] ?? '') === 'realpropertyads') {
            return \Inertia\Inertia::render('RealEstateHowItWorks', [
                'meta' => $this->meta(
                    'How It Works — Real Property Ads',
                    'From listing URL to a live Google Ads campaign in under five minutes. See how Real Property Ads works for real estate agents.',
                ),
            ]);
        }

        return \Inertia\Inertia::render('HowItWorks', [
            'meta' => $this->meta(
                'How It Works — Ads Live in Minutes | sitetospend',
                'Connect your site, and campaigns are built, launched and optimised for you. See how automated Google Ads management works, step by step.',
                'How It Works — From URL to ROI in 3 Steps | sitetospend',
                'Enter your URL, let AI read your brand, find your competitors, and deploy optimised campaigns in minutes.',
                [[
                    '@type' => 'HowTo',
                    'name' => 'How to launch AI-managed ad campaigns with sitetospend',
                    'description' => 'From URL to live campaign in three steps: brand extraction, competitive intelligence, then autonomous optimisation.',
                    'step' => array_map(fn (array $step) => [
                        '@type' => 'HowToStep',
                        'position' => $step['position'],
                        'name' => $step['name'],
                        'text' => $step['text'],
                        'url' => url('/how-it-works').'#step-'.$step['position'],
                    ], [
                        [
                            'position' => 1,
                            'name' => 'Vision AI brand extraction',
                            'text' => 'Enter your website URL. We take a high-resolution screenshot and Gemini Vision reads your hex codes, fonts and brand voice off it.',
                        ],
                        [
                            'position' => 2,
                            'name' => 'Competitive intelligence',
                            'text' => 'The Competitor Discovery agent finds who else is bidding in your space. The Analysis agent reads their sites, extracts their messaging and generates counter-strategies.',
                        ],
                        [
                            'position' => 3,
                            'name' => 'Autonomous optimisation',
                            'text' => 'Deploy in one click. Self-optimising agents fix disapproved ads, Budget Intelligence shifts spend to peak hours, and Creative Testing replaces the variations that are losing.',
                        ],
                    ]),
                ]],
            ),
        ]);
    }

    public function pricing(Request $request)
    {
        $tenant = $request->attributes->get('tenant', config('tenants.'.config('tenants.default')));

        if (($tenant['key'] ?? '') === 'realpropertyads') {
            return \Inertia\Inertia::render('RealEstatePricing', [
                'meta' => $this->meta(
                    'Pricing — Real Property Ads',
                    'One simple package for real estate agents: $1,000 to launch your property campaign, then $500 a month until it sells. No lock-in.',
                ),
            ]);
        }

        $plans = Plan::active()->ordered()->where('is_free', false)->get();
        $faqs = $this->faqs('pricing', $this->setupFeeUsd());

        return \Inertia\Inertia::render('Pricing', [
            'plans' => $plans,
            'faqs' => $faqs,
            'setupFeeUsd' => $this->setupFeeUsd(),
            'meta' => $this->meta(
                // Was "from A$250/mo" while the cards on the page charge US$149
                // and US$249 — the title advertised a price the page contradicts.
                'Pricing — Google Ads Management from US$149/mo | sitetospend',
                'Flat monthly pricing for automated Google and Meta ads management. No percentage of ad spend, no lock-in contract. See plans and start free.',
                null,
                null,
                [$this->faqSchema($faqs)],
            ),
        ]);
    }

    public function about()
    {
        return \Inertia\Inertia::render('About', [
            'meta' => $this->meta(
                'About sitetospend — Ads Management Without an Agency',
                'We built the ad agency we wanted to hire: AI agents that run Google and Meta campaigns properly, at a fraction of agency cost.',
                'About sitetospend — Democratising Digital Advertising with AI',
                'Our mission: agency-level marketing results at a fraction of the cost, run by autonomous AI agents.',
                [[
                    '@type' => 'AboutPage',
                    'name' => 'About sitetospend',
                    'url' => 'https://sitetospend.com/about',
                    'description' => 'sitetospend is on a mission to democratise digital advertising with autonomous AI agents.',
                    'mainEntity' => [
                        '@type' => 'Organization',
                        'name' => 'sitetospend',
                        'url' => 'https://sitetospend.com',
                        'logo' => 'https://sitetospend.com/og-image.png',
                        'description' => 'AI-powered digital advertising platform whose autonomous agents manage and optimise campaigns across Google, Meta, Microsoft and LinkedIn.',
                        'foundingDate' => '2026',
                        'knowsAbout' => ['Digital Advertising', 'AI Marketing', 'Google Ads', 'Facebook Ads', 'Campaign Optimization'],
                    ],
                ]],
            ),
        ]);
    }

    /**
     * ListItem nodes, numbered from one.
     *
     * @param  array<int, array{0: string, 1: string}>  $items  [name, description] pairs.
     * @return array<int, array<string, mixed>>
     */
    private function listItems(array $items): array
    {
        return array_map(fn (int $i, array $item) => [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'name' => $item[0],
            'description' => $item[1],
        ], array_keys($items), $items);
    }

    /**
     * A `config/faqs.php` list with its price placeholders filled in.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    private function faqs(string $key, int $setupFeeUsd): array
    {
        return array_map(fn (array $faq) => [
            ...$faq,
            'answer' => str_replace(':setup_fee', (string) $setupFeeUsd, $faq['answer']),
        ], config('faqs.'.$key, []));
    }

    /**
     * The one-time Google Ads setup fee, in whole US dollars.
     *
     * config is the single source — SetupFeeService charges the cents value from
     * the same key, so a price written into marketing copy could disagree with
     * the price Stripe actually collects.
     */
    private function setupFeeUsd(): int
    {
        return (int) round(config('services.stripe.setup_fee_usd_cents', 99900) / 100);
    }
}
