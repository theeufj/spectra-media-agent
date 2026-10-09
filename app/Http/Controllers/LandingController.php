<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RendersPageMeta;
use App\Models\Plan;
use App\Support\PublicMarketingContent;
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
            return $this->renderPublic($page, [
                'plans' => $plans,
                'meta' => $this->meta(
                    'Real Property Ads — Google Ads Built for Real Estate Agents',
                    'Get more listings and sell faster with AI-built Google Ads campaigns made for real estate agents. Review your business profile, budget and ads before launch.',
                    'Real Property Ads — More Listings. More Closings.',
                    'Stop paying for generic ads. Real Property Ads builds a Google campaign tailored to each property listing, automatically.',
                ),
            ]);
        }
        $paidPrices = $plans->pluck('price_cents')->filter()->map(fn ($cents) => (int) round($cents / 100));

        return $this->renderPublic($page, [
            'plans' => $plans,
            'faqs' => $faqs,
            'setupFeeUsd' => $setupFeeUsd,
            'meta' => $this->meta(
                'AI Google & Meta Ads Management Software | sitetospend',
                'Prepare, review and manage Google and Meta campaigns with AI. Confirm your business profile, keyword plan, creative and budget before launch.',
                'sitetospend — Your AI Marketing Team',
                'Build a campaign from your website and review the ads before launch. Compare ongoing management and a one-time Google Ads setup.',
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
        return $this->renderPublic('Features', [
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
                        ['Competitor Discovery Agent', 'Researches relevant competitors based on your website and confirmed business information.'],
                        ['Competitor Analysis Agent', 'Reads competitor websites, extracts messaging and pricing, generates counter-strategies.'],
                        ['Ad Health Agent', 'Monitors policy status and attempts supported repairs, then checks the result.'],
                        ['Budget Intelligence Agent', 'Adjusts budgets by time-of-day and day-of-week performance.'],
                        ['Creative Intelligence Agent', 'Reviews creative performance and prepares variations when sufficient evidence is available.'],
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
            return $this->renderPublic('RealEstateHowItWorks', [
                'meta' => $this->meta(
                    'How It Works — Real Property Ads',
                    'From listing URL to a reviewed Google Ads campaign. See how Real Property Ads works for real estate agents.',
                ),
            ]);
        }

        return $this->renderPublic('HowItWorks', [
            'meta' => $this->meta(
                'How It Works — Review Your Campaign | sitetospend',
                'Start with your website, confirm the business profile, then review the budget and ads. See what happens before deployment and ongoing monitoring.',
                'How It Works — From Website to Campaign Review | sitetospend',
                'Enter your URL, confirm your brand and business facts, and review the campaign before deployment.',
                [[
                    '@type' => 'HowTo',
                    'name' => 'How to launch AI-managed ad campaigns with sitetospend',
                    'description' => 'From website to campaign: review business facts, research the market, then confirm the plan and ads before deployment.',
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
                            'text' => 'Enter your website URL. Review the extracted brand colours, business facts and offer.',
                        ],
                        [
                            'position' => 2,
                            'name' => 'Competitive intelligence',
                            'text' => 'Review the market research, proposed keywords and message. Competitor website research is context rather than proof of auction participation.',
                        ],
                        [
                            'position' => 3,
                            'name' => 'Autonomous optimisation',
                            'text' => 'Confirm the budget and ads before deployment. Managed campaigns are monitored for delivery, policy and performance; Google review and billing still affect serving.',
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
            return $this->renderPublic('RealEstatePricing', [
                'plans' => Plan::active()->ordered()->where('is_free', false)->get(),
                'faqs' => $this->faqs('pricing', $this->setupFeeUsd()),
                'setupFeeUsd' => $this->setupFeeUsd(),
                'meta' => $this->meta(
                    'Pricing — Real Property Ads',
                    'Choose current ongoing management plans or a one-time Google Ads build for your property campaigns. Review your profile and ads before launch; ad spend is separate.',
                ),
            ]);
        }

        $plans = Plan::active()->ordered()->where('is_free', false)->get();
        $faqs = $this->faqs('pricing', $this->setupFeeUsd());
        $lowestPrice = $plans->where('billing_interval', 'month')->where('price_cents', '>', 0)->min('price_cents');

        return $this->renderPublic('Pricing', [
            'plans' => $plans,
            'faqs' => $faqs,
            'setupFeeUsd' => $this->setupFeeUsd(),
            'meta' => $this->meta(
                'Pricing — Google Ads Management'.($lowestPrice ? ' from US$'.number_format($lowestPrice / 100, 0).'/mo' : '').' | sitetospend',
                'Compare managed Google and Meta ads with a one-time Google Ads setup. Service fees and ad spend are separate. Review your campaign before launch.',
                null,
                null,
                [$this->faqSchema($faqs)],
            ),
        ]);
    }

    public function about()
    {
        return $this->renderPublic('About', [
            'meta' => $this->meta(
                'About sitetospend — Ads Management Without an Agency',
                'Site to Spend brings campaign preparation, ad platform monitoring and performance reporting together for businesses without a large marketing team.',
                'About sitetospend — AI Campaign Preparation & Management',
                'Our mission is to make campaign preparation, management and reporting easier to understand for business owners.',
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

    /** @param array<string, mixed> $props */
    private function renderPublic(string $component, array $props): \Inertia\Response
    {
        return \Inertia\Inertia::render($component, [
            ...$props,
            'publicContent' => PublicMarketingContent::forComponent($component),
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
