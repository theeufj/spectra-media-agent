import React from 'react';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import Hero from '@/Components/Marketing/Hero';
import FeatureGrid, { FeatureSteps } from '@/Components/Marketing/FeatureGrid';
import CtaBand from '@/Components/Marketing/CtaBand';
import {
    ArrowPathIcon,
    ArrowTrendingUpIcon,
    BanknotesIcon,
    CpuChipIcon,
    DocumentTextIcon,
    LinkIcon,
    MapIcon,
    PencilSquareIcon,
    UserGroupIcon,
} from '@heroicons/react/24/outline';

const steps = [
    {
        icon: LinkIcon,
        title: '1. Connect and paste your listing',
        body: 'Link your Google Ads account with one click. Then paste the URL of the property you want to advertise — your listing on realestate.com.au, Domain, or your own agency website.',
        bullets: [
            'One-click Google Ads connection',
            'Works with any listing URL',
            'No spreadsheets or forms to fill in',
            'Your agency branding applied automatically',
        ],
        panel: {
            title: 'Paste the listing URL',
            detail: 'realestate.com.au · Domain · your own site',
        },
    },
    {
        icon: CpuChipIcon,
        title: '2. AI builds the campaign',
        body: 'Our AI reads every detail of the listing — bedrooms, price, suburb, key features — and writes ad copy, selects keywords, and sets targeting for the buyers most likely to enquire. Ready in seconds.',
        bullets: [
            'Ad headlines and descriptions written from the listing',
            'Keywords matched to the suburb, price range, and property type',
            'Buyer targeting by location, search intent, and device',
            'Retargeting audiences for people who viewed the listing',
        ],
        panel: {
            title: 'AI reads the listing',
            detail: 'Bedrooms · price · suburb · features · photos',
        },
    },
    {
        icon: ArrowTrendingUpIcon,
        title: '3. Campaigns run and improve on their own',
        body: 'Once live, AI agents monitor the campaign every day — fixing disapproved ads, shifting budget to peak hours, and testing new ad variations. You get a weekly performance report. When the property sells, you pause with one click.',
        bullets: [
            'Daily bid and budget optimisation',
            'Disapproved ads fixed and resubmitted automatically',
            'Weekly email report — leads, clicks, cost per enquiry',
            'Pause instantly when the property sells',
        ],
        panel: {
            title: 'Improving every day',
            detail: 'Launch, optimise, sell, pause',
        },
    },
];

// Was six emoji tiles. Line icons match the rest of both skins and, unlike
// emoji, are not announced as "clipboard, writing hand, world map" by a reader.
const agents = [
    {
        icon: DocumentTextIcon,
        title: 'Listing Reader',
        body: 'Reads your property URL — bedrooms, price, suburb, features — and builds the campaign brief automatically.',
    },
    {
        icon: PencilSquareIcon,
        title: 'Ad Copywriter',
        body: 'Writes Google Search headlines and descriptions tailored to the property and your agency brand voice.',
    },
    {
        icon: MapIcon,
        title: 'Location Targeter',
        body: 'Sets geographic targeting to the right suburbs, postcodes, and school districts for that listing\'s buyer profile.',
    },
    {
        icon: BanknotesIcon,
        title: 'Budget Optimiser',
        body: 'Shifts ad spend to the hours and days when property buyers are most active in your area.',
    },
    {
        icon: ArrowPathIcon,
        title: 'Ad Fixer',
        body: 'Catches any disapproved ads, rewrites them to pass Google\'s policy checks, and resubmits — without you lifting a finger.',
    },
    {
        icon: UserGroupIcon,
        title: 'Audience Builder',
        body: 'Creates retargeting lists from people who viewed the listing page, and lookalike audiences from your past buyers.',
    },
];

/*
 * No <Head> here — LandingController serves this skin's own title, description
 * and Open Graph pair. It used to inherit the flagship's, so realpropertyads.com
 * advertised "| sitetospend" to every crawler that does not run JavaScript,
 * while the tags written here appended a second, different set on top.
 */
export default function RealEstateHowItWorks({ auth }) {
    return (
        <>
            <PageTitle />
            <div className="min-h-screen bg-white">
                <Header auth={auth} />

                <main>
                    <Hero
                        eyebrow="How it works"
                        headline={
                            <>
                                {'Listing URL to live campaign '}
                                <span className="block">in under 5 minutes</span>
                            </>
                        }
                        sub="Paste your property listing. Our AI does everything else — copy, targeting, launch, and daily optimisation."
                    />

                    <FeatureSteps
                        items={steps}
                        cta={{ href: '/register', label: 'Get started' }}
                        note="No credit card required"
                    />

                    <FeatureGrid
                        title="Six AI agents working for you"
                        sub="Each one is specialised for a different part of getting your listing in front of the right buyers."
                        items={agents}
                    />

                    <CtaBand
                        title="Ready to launch your first listing campaign?"
                        body="Paste your first listing URL and your campaign is live in minutes."
                        primaryCta={{ href: '/register', label: 'Start your first campaign' }}
                        secondaryCta={{ href: '/pricing', label: 'View pricing' }}
                    />
                </main>

                <Footer />
            </div>
        </>
    );
}
