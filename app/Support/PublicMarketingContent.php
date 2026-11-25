<?php

namespace App\Support;

use App\Models\Plan;

/** Core public copy shared by the initial HTML and the interactive pages. */
class PublicMarketingContent
{
    /** @return array<string, mixed> */
    public static function forComponent(string $component, bool $singlePlan, ?Plan $plan = null): array
    {
        $pages = [
            'Landing' => [
                'eyebrow' => $plan ? 'US$'.number_format($plan->price_cents / 100).'/month · Ad spend separate' : 'Google Ads for small businesses',
                'headline' => 'Google Ads management for your small business',
                'intro' => 'Preview ads written for your website. Approve your campaign and budget before launch, then follow spend, enquiries and changes in your workspace.',
            ],
            'Features' => [
                'eyebrow' => 'Platform features',
                'headline' => $singlePlan ? 'Google Ads tools for small businesses' : 'Everything you need to manage your paid ads',
                'intro' => 'Keyword research, campaign preparation, budget monitoring, conversion tracking and creative reviews in one place.',
            ],
            'HowItWorks' => [
                'eyebrow' => 'How it works',
                'headline' => 'From your website to a reviewed campaign',
                'intro' => 'Enter your website address, confirm your business profile, and review the budget and ads before launch.',
            ],
            'Pricing' => [
                'eyebrow' => 'Pricing',
                'headline' => 'Google Ads management for one business',
                'intro' => 'One monthly management fee covers your Google campaigns in one workspace. Choose your advertising budget separately.',
            ],
            'About' => [
                'eyebrow' => 'About us',
                'headline' => $singlePlan ? 'About Site to Spend' : 'Advertising tools for businesses without a large marketing team',
                'intro' => 'Site to Spend brings campaign preparation, platform monitoring and performance reporting together, so business owners can understand what is running and what needs attention.',
            ],
            'RealEstateLanding' => [
                'eyebrow' => 'Built for real estate agents',
                'headline' => 'Google Ads built around your property business',
                'intro' => 'Start with your listing or business website. Confirm your service area, offer and audience, then review your Google Ads campaign before launch.',
            ],
            'RealEstateHowItWorks' => [
                'eyebrow' => 'How it works',
                'headline' => 'From listing URL to reviewed, ready-to-launch ads',
                'intro' => 'Start with your listing, confirm the business profile, and review the budget and creative before launch.',
            ],
        ];
        $pages['RealEstatePricing'] = [
            'eyebrow' => 'Pricing for property businesses',
            'headline' => 'Choose how your property campaigns are managed',
            'intro' => 'Ongoing management for your property business, or a Google Ads campaign built once for you to run. Start with a listing or business website, confirm the local offer and choose your advertising allowance separately from the service fee.',
        ];
        $isProperty = str_starts_with($component, 'RealEstate');

        if ($singlePlan) {
            return [
                'type' => 'marketing',
                ...$pages[$component],
                'sections' => [
                    ['title' => 'Preview your Google ads', 'body' => 'Start with your website. Review the proposed keywords, copy and service area so the campaign describes the work you want to win.'],
                    ['title' => 'Set a budget before launch', 'body' => 'Choose the daily advertising budget you want to fund. Your monthly subscription covers campaign management. Google advertising spend is additional and funded through prepaid credit.'],
                    ['title' => 'Track enquiries and spend', 'body' => 'Choose what to measure, such as a completed booking or quote request. Once tracking is tested, your workspace shows spend, measured conversions and campaign changes.'],
                ],
                'links' => [
                    ['href' => '/google-ads-management', 'label' => 'Google Ads management'],
                    ['href' => '/pricing', 'label' => 'Pricing and ad spend'],
                    ['href' => '/terms-of-service', 'label' => 'Subscription and account terms'],
                    ['href' => '/blog', 'label' => 'Google Ads guides'],
                ],
            ];
        }

        return [
            'type' => 'marketing',
            ...$pages[$component],
            'sections' => $component === 'RealEstatePricing' ? [
                [
                    'title' => 'Match the plan to your property marketing work',
                    'body' => 'Decide whether you need a campaign for a current listing, enquiries from sellers in your service area, or another property service. These are different offers: the destination page, message and useful enquiry should reflect the one you choose. A managed plan includes ongoing monitoring and supported campaign adjustments. A one-time Google Ads build is prepared for a paused handover, with no ongoing management. Compare the live plan cards for platform coverage and inclusions rather than assuming every package covers every channel.',
                ],
                [
                    'title' => 'Keep the media allowance and listing facts current',
                    'body' => 'Your service fee pays for campaign preparation or management; advertising spend is additional. Choose an allowance you can afford while the campaign gathers evidence. Confirm the suburb, service area, listing URL and offer before launch. If a property sells, the price changes or availability ends, update the business sources and review the affected ads and destination. A campaign should not keep inviting enquiries for an offer you can no longer fulfil. Budget changes alone cannot fix outdated property facts or a broken enquiry page.',
                ],
                [
                    'title' => 'Decide who handles enquiries and the account after setup',
                    'body' => 'For property marketing, a useful result might be a confirmed viewing enquiry or an appraisal request; a page view does not establish either. Make sure someone can respond, and test the successful form action on your website. Tracking may need website access or tag installation. With the one-time package, accept the Google Ads administrator invitation, inspect the paused campaign and complete your Google billing before enabling it. Ongoing management belongs to the subscription plans. Google review, verification and auction eligibility still determine whether ads can serve.',
                ],
            ] : [
                [
                    'title' => $isProperty ? 'Advertising for the area you serve' : 'Start with your offer and your customers',
                    'body' => $isProperty
                        ? 'A property campaign needs a clear service area and a useful destination for the enquiry. Review the location, listing facts and the action you want visitors to take before deploying.'
                        : 'Your website is the starting point. Review the extracted facts, keyword intent and destination page so the campaign advertises the service you actually sell.',
                ],
                [
                    'title' => 'Choose ongoing management or a one-time build',
                    'body' => 'Managed plans include ongoing campaign monitoring. The one-time Google Ads setup creates a campaign for handover. Your service fee and advertising budget are separate; compare the current plans before choosing.',
                ],
                [
                    'title' => 'Measure the action that matters',
                    'body' => 'Decide whether success means an enquiry, a purchase or a paid signup. Tracking must be installed and checked on the destination website. A deployed campaign still depends on Google review, account billing and auction eligibility before it can serve.',
                ],
            ],
            'links' => $isProperty ? [
                ['href' => '/how-it-works', 'label' => 'How property campaigns work'],
                ['href' => '/pricing', 'label' => 'Compare current plans'],
            ] : [
                ['href' => '/google-ads-management', 'label' => 'Google Ads management for small businesses'],
                ['href' => '/ai-ads-management', 'label' => 'How AI ad management works'],
                ['href' => '/google-ads-setup', 'label' => 'One-time Google Ads setup'],
                ['href' => '/blog', 'label' => 'Practical Google Ads guides'],
            ],
        ];
    }
}
