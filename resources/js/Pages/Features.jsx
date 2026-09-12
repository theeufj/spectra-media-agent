import React from 'react';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import Hero, { brandTint, CtaLink, CTA_PRIMARY, CTA_SECONDARY, CTA_SIZE } from '@/Components/Marketing/Hero';
import FeatureGrid from '@/Components/Marketing/FeatureGrid';
import CtaBand from '@/Components/Marketing/CtaBand';
import {
    ArrowsRightLeftIcon,
    BanknotesIcon,
    BeakerIcon,
    ChartBarIcon,
    CheckBadgeIcon,
    ChartPieIcon,
    MagnifyingGlassIcon,
    PaintBrushIcon,
    RectangleGroupIcon,
    SparklesIcon,
    SwatchIcon,
    UserGroupIcon,
    WrenchScrewdriverIcon,
} from '@heroicons/react/24/outline';

/*
 * No <Head> here. Title, description, Open Graph pair and JSON-LD are built by
 * LandingController and printed server-side by app.blade.php. See
 * App\Http\Controllers\Concerns\RendersPageMeta.
 *
 * This page was the last one still hand-rolling its own sections, and it had
 * collected every defect the shared Marketing components were built to end:
 *
 *   - `from-brand-primary/10`, `text-brand-primary/50`, `bg-brand-primary/10`.
 *     tailwind.config maps brand.* to a bare CSS variable, which Tailwind 3
 *     cannot split into channels, so an opacity modifier on one compiles to no
 *     CSS at all. Verified against the shipped bundle: none of the three exist.
 *     The hero gradient rendered as plain white and the agents eyebrow fell back
 *     to the page's gray-800 — 1.4:1 on the brand-darker band behind it.
 *   - `bg-${agent.color}-500/30` and `text-${agent.color}-300`, built from a
 *     variable. Tailwind scans source for literal class names, so none of those
 *     reached the bundle either: the icon chips had no fill and every
 *     "Runs: Weekly" line was gray-800 on orange.
 *   - 🔍 📊 🩹 💰 🎨 👥 as icons, which render as whatever the visitor's OS ships
 *     and are read out literally by a screen reader.
 *
 * Hero, FeatureGrid and CtaBand already solve all of it, and their colour pairs
 * are computed against both tenant palettes.
 */

// Same six agents as the landing page, and deliberately the same icons — a
// visitor arriving from there should recognise them.
const agents = [
    {
        icon: MagnifyingGlassIcon,
        title: 'Competitor Discovery',
        body: 'Looks at what you do and finds the businesses competing for the same customers, including the ones you did not know about.',
        meta: 'Runs weekly',
    },
    {
        icon: ChartBarIcon,
        title: 'Competitor Analysis',
        body: 'Reads your competitors’ websites and works out which angles you can use against them.',
        meta: 'Runs weekly',
    },
    {
        icon: WrenchScrewdriverIcon,
        title: 'Ad Health & Fixing',
        body: 'Scans for rejected ads and rewrites them so they pass review. Also pauses the ads that are not pulling their weight.',
        meta: 'Runs every 4 hours',
    },
    {
        icon: BanknotesIcon,
        title: 'Smart Budget Shifting',
        body: 'Moves budget to the hours your customers are actually online — back at quiet times, harder when it counts.',
        meta: 'Runs hourly',
    },
    {
        icon: PaintBrushIcon,
        title: 'Ad Creative Testing',
        body: 'Splits your headlines into A/B tests, tracks which wins at 95% confidence, and promotes it.',
        meta: 'Runs daily',
    },
    {
        icon: UserGroupIcon,
        title: 'Audience Growth',
        body: 'Syncs your customer list and builds lookalike audiences, so you keep reaching people like your best customers.',
        meta: 'Runs weekly',
    },
];

const capabilities = [
    {
        icon: MagnifyingGlassIcon,
        title: 'Know your competition',
        body: 'We find who you are up against, read their sites, and work out how you stand out. Updated weekly without you asking.',
    },
    {
        icon: WrenchScrewdriverIcon,
        title: 'Ads that fix themselves',
        body: 'Rejected by Google? We rewrite and resubmit automatically. Ads that stop performing get paused before they burn budget.',
    },
    {
        icon: BanknotesIcon,
        title: 'Smart budget shifting',
        body: 'Budget moves to the times people are most likely to buy. Less wasted overnight, more firepower at peak.',
    },
    {
        icon: BeakerIcon,
        title: 'Always testing your ads',
        body: 'Headlines are split into A and B groups daily. At 95% confidence the winner is promoted automatically.',
    },
    {
        icon: UserGroupIcon,
        title: 'Reach more of the right people',
        body: 'Your customer list syncs weekly and lookalike audiences build themselves. Nothing to upload.',
    },
    {
        icon: SwatchIcon,
        title: 'Your brand, automatically',
        body: 'We read your website for colours, fonts and tone. Every ad looks like your own design team made it.',
    },
    {
        icon: RectangleGroupIcon,
        title: 'Every Google ad format',
        body: 'Search, Display, Video and Performance Max, all managed from one place with the AI writing the assets.',
    },
    {
        icon: ChartPieIcon,
        title: 'Conversion tracking, set up for you',
        body: 'We wire up tracking so you always know what the ads deliver — sales, leads, calls, whatever matters.',
    },
    {
        icon: SparklesIcon,
        title: 'Try it free first',
        body: 'Explore everything with no commitment. Real ad copy, real images, real competitor insight. Upgrade when you are ready.',
    },
];

const LIVE_PLATFORMS = ['Google Ads', 'Meta Ads', 'Microsoft Ads', 'LinkedIn Ads'];
const PLANNED_PLATFORMS = ['Reddit Ads', 'TikTok Ads'];

/**
 * One platform, live or planned.
 *
 * The old markup gave both states the same green tick and told them apart with
 * `grayscale`, which is a filter — the tick stayed a tick, so "Coming Soon"
 * read as "done" to anyone skimming. Live gets the tick; planned gets a
 * different glyph and says so in words.
 */
function Platform({ name, live }) {
    const Icon = live ? CheckBadgeIcon : ArrowsRightLeftIcon;

    return (
        <li className="flex flex-col items-center text-center">
            <span
                className={`flex h-14 w-14 items-center justify-center rounded-full sm:h-20 sm:w-20 ${
                    live ? 'text-brand-darker' : 'bg-gray-100 text-gray-500'
                }`}
                style={live ? { backgroundColor: brandTint(12) } : undefined}
            >
                <Icon className="h-7 w-7 sm:h-9 sm:w-9" aria-hidden="true" />
            </span>
            <span className="mt-3 text-sm font-semibold text-gray-900">{name}</span>
            <span className={`text-xs font-medium ${live ? 'text-brand-darker' : 'text-gray-500'}`}>
                {live ? 'Available now' : 'Coming soon'}
            </span>
        </li>
    );
}

export default function Features({ auth }) {
    return (
        <>
            <PageTitle />
            <div className="min-h-screen bg-white">
                <Header auth={auth} />

                <main>
                    <Hero
                        eyebrow="Platform features"
                        headline="Everything you need to win at paid ads"
                        sub="Six AI specialists, full Google Ads support, automatic brand matching and conversion tracking — all running around the clock."
                    />

                    {/* Platforms */}
                    <section className="border-b border-gray-200 bg-white py-12 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl text-center">
                                <h2 className="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                                    One-click deployment to all your platforms
                                </h2>
                                <p className="mt-3 text-base leading-relaxed text-gray-600 sm:mt-4 sm:text-lg">
                                    We set up and manage the ad accounts; the agents handle the rest.
                                </p>
                            </div>
                            {/*
                                A list, because that is what it is — six items a
                                screen reader should announce as six, not as six
                                loose divs. Three across on a phone rather than
                                one, which is what a 96px circle per row cost.
                            */}
                            <ul className="mx-auto mt-8 grid max-w-3xl grid-cols-3 gap-x-4 gap-y-8 sm:mt-16 sm:grid-cols-6 sm:gap-x-8">
                                {LIVE_PLATFORMS.map((name) => (
                                    <Platform key={name} name={name} live />
                                ))}
                                {PLANNED_PLATFORMS.map((name) => (
                                    <Platform key={name} name={name} live={false} />
                                ))}
                            </ul>
                        </div>
                    </section>

                    <FeatureGrid
                        eyebrow="Always working for you"
                        title="Your 24/7 marketing team"
                        sub="Six AI specialists, each focused on a different part of your advertising."
                        items={agents}
                        footer={
                            <CtaLink href="/register" className={`${CTA_PRIMARY} ${CTA_SIZE}`}>
                                Put these agents to work
                            </CtaLink>
                        }
                    />

                    <FeatureGrid
                        eyebrow="Built for real businesses"
                        title="Everything you need to launch great ads and keep them running"
                        sub="From writing the ads to tracking what works, so you can get on with the business."
                        items={capabilities}
                        background="white"
                        footer={
                            <CtaLink href="/pricing" className={`${CTA_SECONDARY} ${CTA_SIZE}`}>
                                See pricing
                            </CtaLink>
                        }
                    />

                    <CtaBand
                        title="See all features in action"
                        body="Sign up free and explore everything sitetospend does. No credit card required."
                        primaryCta={{ href: '/register', label: 'Get started free' }}
                        secondaryCta={{ href: '/pricing', label: 'View pricing' }}
                    />
                </main>

                <Footer />
            </div>
        </>
    );
}
