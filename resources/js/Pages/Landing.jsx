import PublicMarketingResources from '@/Components/Marketing/PublicMarketingResources';
import React from 'react';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import WebsiteAdPreview from '@/Components/Marketing/WebsiteAdPreview';
import { AD_PREVIEW_CTA, CtaLink, CTA_PRIMARY, CTA_SECONDARY, CTA_SIZE } from '@/Components/Marketing/Hero';
import StatsStrip from '@/Components/Marketing/StatsStrip';
import FeatureGrid from '@/Components/Marketing/FeatureGrid';
import PricingTable, { PlanPrice } from '@/Components/Marketing/PricingTable';
import CtaBand from '@/Components/Marketing/CtaBand';
import FaqAccordion from '@/Components/Marketing/FaqAccordion';
import SetupOnlyOffer from '@/Components/Marketing/SetupOnlyOffer';
import {
    BanknotesIcon,
    ChartBarIcon,
    EyeIcon,
    MagnifyingGlassIcon,
    PaintBrushIcon,
    RocketLaunchIcon,
    UserGroupIcon,
    WrenchScrewdriverIcon,
} from '@heroicons/react/24/outline';

/*
 * Line icons, not emoji. The flagship used 👁️ 🧠 🚀 🔍 📊 🩹 💰 🎨 👥 where the
 * real-estate skin used heroicons; emoji render as whatever the visitor's OS
 * ships and are read out literally ("eye", "brain") by screen readers.
 */
const previewCta = { ...AD_PREVIEW_CTA, href: '#build-my-ads' };
const steps = [
    {
        icon: EyeIcon,
        title: 'We read your website',
        body: 'Enter your website to preview ad copy based on your business. You can review the wording before creating an account.',
    },
    {
        icon: MagnifyingGlassIcon,
        title: 'We find your competitors',
        body: 'We look at who else is advertising in your space, check out their messaging, and figure out how to beat them.',
    },
    {
        icon: RocketLaunchIcon,
        title: 'Review your ads before launch',
        body: 'Approve your ads and budget when you are ready. With a managed plan, agents monitor delivery and flag issues that need your input.',
    },
];

// The one-line descriptions are the plain-English ones already used to answer
// "What do the AI specialists actually do?" on the pricing page.
const agents = [
    {
        icon: MagnifyingGlassIcon,
        title: 'Competitor Discovery',
        body: 'Finds who else is bidding in your space, including the competitors you did not know you had.',
    },
    {
        icon: ChartBarIcon,
        title: 'Competitor Analysis',
        body: 'Digs into their websites and offers to find the angles you can use against them.',
    },
    {
        icon: WrenchScrewdriverIcon,
        title: 'Self-Optimising',
        body: 'Checks policy and delivery problems, attempts supported repairs and reports issues that need your input.',
    },
    {
        icon: BanknotesIcon,
        title: 'Budget Intelligence',
        body: 'Moves your budget to the hours and days your customers are actually active.',
    },
    {
        icon: PaintBrushIcon,
        title: 'Creative Intelligence',
        body: 'Tests different ad variations, keeps the winners, and writes replacements for the losers.',
    },
    {
        icon: UserGroupIcon,
        title: 'Audience Intelligence',
        body: 'Finds new people who look like the customers you already have.',
    },
];

const stats = [
    { value: 'Six', label: 'AI specialists per account', detail: 'working daily, not weekly' },
    { value: 'Google & Meta', label: 'managed advertising', detail: 'platform access depends on your plan' },
    { value: 'Review first', label: 'you approve before launch', detail: 'check your profile, budget and ads' },
];

/*
 * No <Head> here. LandingController owns every tag; app.blade.php prints them.
 * The <title> and <meta description> written here were duplicates of the
 * server's, not replacements for them — Inertia only swaps tags it owns.
 */
export default function Landing({ auth, publicContent, plans = [], faqs = [], setupFeeUsd = 999 }) {
    const paidPlans = plans.filter(p => p.price_cents > 0 && !p.is_free);
    const lowestPrice = paidPlans.length > 0 ? Math.round(Math.min(...paidPlans.map(p => p.price_cents)) / 100) : 99;

    return (
        <>
            <PageTitle />
            <div className="min-h-screen bg-white">
                <Header auth={auth} guestCta={previewCta} />

                <main>
                    <WebsiteAdPreview
                        eyebrow={publicContent.eyebrow}
                        headline={publicContent.headline}
                        sub={publicContent.intro}
                        secondaryCta={{ href: '/how-it-works', label: 'See how it works' }}
                        note="Free preview · No credit card required"
                    />

                    <StatsStrip items={stats} />

                    <FeatureGrid
                        title="Up and running in 3 steps"
                        sub="We handle the hard parts."
                        items={steps}
                        background="white"
                        footer={
                            <CtaLink href="/how-it-works" className="inline-flex min-h-[44px] items-center font-semibold text-brand-darker hover:underline">
                                Learn more about how it works
                            </CtaLink>
                        }
                    />

                    <FeatureGrid
                        eyebrow="Always working for you"
                        title="Your 24/7 AI PPC campaign manager"
                        sub="Six specialists, each focused on a different part of your advertising, running around the clock."
                        items={agents}
                        footer={
                            <div className="flex flex-col items-center justify-center gap-4 sm:flex-row">
                                <CtaLink href={previewCta.href} className={`${CTA_PRIMARY} ${CTA_SIZE}`}>{previewCta.label}</CtaLink>
                                <CtaLink href="/features" className={`${CTA_SECONDARY} ${CTA_SIZE}`}>See all features</CtaLink>
                            </div>
                        }
                    />

                    {/* Pricing teaser */}
                    <PricingTable
                        title="Simple, honest pricing"
                        sub={`Management plans from US$${lowestPrice}/month. Advertising spend is separate.`}
                        plans={plans.map((plan) => ({
                            id: plan.id,
                            name: plan.name,
                            description: plan.description,
                            badge: plan.badge_text,
                            highlighted: plan.is_popular,
                            price: <PlanPrice plan={plan} />,
                        }))}
                        footnote={
                            <div className="mx-auto max-w-3xl space-y-8">
                                <CtaLink href="/pricing" className={`${CTA_PRIMARY} ${CTA_SIZE}`}>
                                    Compare plans
                                </CtaLink>
                                {/*
                                    Directly under the monthly plans, because
                                    "US$999 once" only means anything next to the
                                    figure it is an alternative to.
                                */}
                                <SetupOnlyOffer priceUsd={setupFeeUsd} href="/google-ads-setup" label="See the one-time setup" />
                            </div>
                        }
                    />

                    {/* Why It Works */}
                    <div className="bg-white py-12 sm:py-24">
                        <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                            <h2 className="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                                Ad Management with a Clear Goal and Activity History
                            </h2>
                            <div className="mt-5 space-y-4 text-base leading-relaxed text-gray-600 sm:mt-6 sm:text-lg">
                                <p>
                                    Small advertisers lose money in the gaps, not the strategy. A headline gets disapproved on
                                    a Friday and nobody notices until Monday. A keyword that converted in March quietly stops
                                    working in June. Budget sits in an ad group that has not produced a lead in weeks.
                                </p>
                                <p>
                                    Our agents monitor campaigns, keywords and creative, record the reasons for supported
                                    changes, and check delivery after a repair. Some issues need your input, such as billing,
                                    advertiser verification or website access. Results depend on your offer, landing page,
                                    measurement and the auctions your campaign can enter.
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* FAQ */}
                    <div className="border-t border-gray-200 bg-gray-50 py-12 sm:py-24">
                        <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                            <h2 className="text-center text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                                Common questions
                            </h2>
                            <p className="mx-auto mt-3 max-w-xl text-center text-base text-gray-600 sm:text-lg">
                                The things people ask before they sign up.
                            </p>
                            {/*
                                Collapsed. Rendered open, these eight answers ran
                                to 2,585px on a 390px phone — three full screens
                                of prose between the pricing table and the
                                closing CTA, which is the last place a visitor
                                who has just seen the price should have to scroll
                                through. FaqAccordion keeps every answer in the
                                DOM, so nothing is lost to a crawler.
                            */}
                            <div className="mt-8 sm:mt-12">
                                <FaqAccordion items={faqs} />
                            </div>
                        </div>
                    </div>

                    <CtaBand
                        title="Preview ads for your business"
                        body="See sample copy based on your website, then decide whether to create an account."
                        primaryCta={previewCta}
                        secondaryCta={{ href: '/login', label: 'Sign in' }}
                    />
                <PublicMarketingResources content={publicContent} />
                </main>

                <Footer guestCta={previewCta} />
            </div>
        </>
    );
}
