import React from 'react';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import Hero from '@/Components/Marketing/Hero';
import StatsStrip from '@/Components/Marketing/StatsStrip';
import FeatureGrid from '@/Components/Marketing/FeatureGrid';
import CtaBand from '@/Components/Marketing/CtaBand';
import {
    ArrowTrendingUpIcon,
    ClockIcon,
    CpuChipIcon,
    DocumentChartBarIcon,
    HomeModernIcon,
    LinkIcon,
    MapPinIcon,
    RocketLaunchIcon,
    UserGroupIcon,
} from '@heroicons/react/24/outline';

/*
 * This page and Landing.jsx are now the same components with different copy.
 * They used to be two independent implementations, which is why the two brands
 * drifted: the centred hero, the CTA pair and the line icons were all written
 * here and never reached the flagship. Anything visual belongs in
 * Components/Marketing, not in either page.
 */

const features = [
    {
        icon: HomeModernIcon,
        title: 'Property Listing Ads',
        body: 'Automatically generate Google Search and Display ads for each listing — tailored to the property features, price point, and neighbourhood.',
    },
    {
        icon: UserGroupIcon,
        title: 'Seller Lead Generation',
        body: 'Target homeowners who are likely to sell with "Sell My House" campaigns, retargeting ads, and lookalike audiences built from your existing clients.',
    },
    {
        icon: MapPinIcon,
        title: 'Hyper-Local Targeting',
        body: 'Reach buyers in the specific suburbs, postcodes, and school districts your listings are in — not just broad metro areas.',
    },
    {
        icon: ArrowTrendingUpIcon,
        title: 'Automated Optimisation',
        body: 'AI agents monitor your campaigns around the clock — pausing underperformers, boosting winning ads, and reallocating budget to what\'s converting.',
    },
    {
        icon: DocumentChartBarIcon,
        title: 'Weekly Performance Reports',
        body: 'Clear, branded reports delivered to your inbox every week — impressions, clicks, leads, cost per lead. No spreadsheets, no guesswork.',
    },
    {
        icon: ClockIcon,
        title: 'Launch in Minutes',
        body: 'Connect your Google Ads account, add your first listing URL, and your first campaign is live — no agency, no setup fees, no learning curve.',
    },
];

const steps = [
    {
        icon: LinkIcon,
        title: 'Connect your accounts',
        body: 'Link Google Ads in one click. Our AI reads your agency website to understand your brand voice, style, and market.',
    },
    {
        icon: CpuChipIcon,
        title: 'Add your listings',
        body: 'Paste the URL of a property you want to promote. The AI scans the listing and writes ad copy, picks keywords, and sets your targeting automatically.',
    },
    {
        icon: RocketLaunchIcon,
        title: 'Launch and let the AI work',
        body: 'Your campaigns go live. The AI monitors performance, adjusts bids, and optimises daily — while you focus on clients and closings.',
    },
];

const stats = [
    { value: '3×', label: 'more leads per dollar', detail: 'vs. running ads manually' },
    { value: '< 5 min', label: 'to launch a campaign', detail: 'from listing URL to live ads' },
    { value: '24/7', label: 'AI optimisation', detail: 'while you focus on clients' },
];

/*
 * No <Head> here — LandingController serves this skin's own title, description
 * and Open Graph pair. It used to inherit the flagship's, so realpropertyads.com
 * advertised "| sitetospend" to every crawler that does not run JavaScript,
 * while the tags written here appended a second, different set on top.
 */
export default function RealEstateLanding({ auth }) {
    return (
        <>
            <PageTitle />
            <div className="min-h-screen bg-white">
                <Header auth={auth} />

                <main>
                    <Hero
                        eyebrow="Built exclusively for real estate agents"
                        headline={
                            <>
                                {'Get more listings. '}
                                <span className="block text-brand-darker">Sell faster.</span>
                            </>
                        }
                        sub="AI-powered Google Ads that write themselves around your listings — targeting the right buyers and sellers in your market, 24/7."
                        primaryCta={{ href: '/register', label: 'Start your first campaign' }}
                        secondaryCta={{ href: '/pricing', label: 'View pricing' }}
                        note="No credit card required. Set up in under 5 minutes."
                    />

                    <StatsStrip items={stats} />

                    <FeatureGrid
                        title="Everything a top-producing agent needs"
                        sub="Purpose-built for real estate. Not a generic marketing tool with a real estate checkbox."
                        items={features}
                    />

                    <FeatureGrid
                        title="Up and running in three steps"
                        items={steps}
                        background="white"
                    />

                    <CtaBand
                        title="Ready to fill your pipeline?"
                        body="Join real estate agents who are using AI to win more listings and sell faster — without hiring a marketing agency."
                        primaryCta={{ href: '/register', label: 'Start free' }}
                        secondaryCta={{ href: '/login', label: 'Log in' }}
                        note="No credit card needed to explore."
                    />
                </main>

                <Footer />
            </div>
        </>
    );
}
