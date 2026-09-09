import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import DemoResultsPanel from '@/Components/DemoResultsPanel';
import Hero, { brandTint, CtaLink, CTA_PRIMARY, CTA_SECONDARY, CTA_SIZE, CTA_SIZE_COMPACT } from '@/Components/Marketing/Hero';
import StatsStrip from '@/Components/Marketing/StatsStrip';
import FeatureGrid from '@/Components/Marketing/FeatureGrid';
import PricingTable, { PlanPrice } from '@/Components/Marketing/PricingTable';
import CtaBand from '@/Components/Marketing/CtaBand';
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
import { trackConversion } from '@/utils/conversions';

/*
 * The home page previously carried a FAQPage schema whose questions appeared
 * nowhere on the page. Google's structured data policy requires the answer text
 * to be visible to the visitor, so the markup was both ineligible for rich
 * results and no help to anyone reading the page. One array now feeds the JSON-LD
 * and the rendered section, which keeps them from drifting apart again.
 */
const faqs = [
    {
        question: 'How does sitetospend work?',
        answer: 'You enter your website address and nothing else. Our vision AI reads the site to pick up your colours, fonts, tone of voice and what you actually sell, then researches who else is bidding on your keywords and how they position themselves. From there it builds the campaign structure, writes the ad copy, sets the initial bids and pushes everything live in your own ad accounts. Six agents keep working on the campaigns from that point on.',
    },
    {
        question: 'Which ad platforms does sitetospend support?',
        answer: 'Google Ads, Meta across Facebook and Instagram, Microsoft Ads on Bing, and LinkedIn Ads, all managed from a single dashboard. You connect accounts you already own rather than handing budget to a reseller, so your spend history, your conversion data and your audience lists stay with you if you ever decide to leave.',
    },
    {
        question: 'Do I need a credit card to start?',
        answer: 'No. You can connect your site and see the brand extraction, the competitor research and the full campaign we would build for you before paying anything at all. A card is only required at the point you decide to push those campaigns live and start spending on ads.',
    },
    {
        question: 'Will I still own my ad accounts and data?',
        answer: 'Yes. Everything is built inside your own Google Ads and Meta accounts, under your billing, with your name on it. There is no lock-in contract and no thirty-day notice period to serve. If you cancel, the campaigns, keyword research and conversion tracking we built stay exactly where they are, running or paused as you prefer.',
    },
    {
        question: 'How is this different from hiring an agency?',
        answer: 'A traditional agency charges a monthly retainer plus a percentage of whatever you spend, and in practice a junior account manager reviews your campaigns perhaps once a week. Our agents check performance every day, act on what they find within hours rather than at the next reporting cycle, and charge a flat fee that does not climb as your ad budget grows.',
    },
    {
        question: 'What happens when an ad gets disapproved?',
        answer: 'The self-optimising agent spots the disapproval, works out which advertising policy was triggered, rewrites the offending headline or description and resubmits it for review. Most disapprovals are cleared before you would have noticed them, which matters more than it sounds: a disapproved ad serves nobody and quietly costs you every impression it should have won.',
    },
    {
        question: 'How quickly will I see results?',
        answer: 'Campaigns are usually live within minutes of connecting your website. Meaningful optimisation needs data to work with, so expect the first week to be largely about learning which searches and audiences respond, and the second to be where budget starts shifting decisively toward the keywords and creatives that actually convert.',
    },
    {
        question: 'Do I need to know anything about Google Ads?',
        answer: 'Not a thing. The dashboard is written in plain language rather than platform jargon, and every change an agent makes is logged alongside the reason it made it. If you want to understand the detail it is all there to read; if you would rather never open it, the campaigns carry on without you.',
    },
];

// Fallback only — LandingController is the source of truth. Kept in sync so a
// render without the meta prop does not silently serve different copy.
const DEFAULT_META = {
    title: 'AI Google & Meta Ads Automation Software | sitetospend',
    description: 'Automate your Google and Meta PPC campaigns with AI. Let intelligent agents handle keyword research, bidding, budgets, and tracking to maximize your ROI.',
};

/*
 * Line icons, not emoji. The flagship used 👁️ 🧠 🚀 🔍 📊 🩹 💰 🎨 👥 where the
 * real-estate skin used heroicons; emoji render as whatever the visitor's OS
 * ships and are read out literally ("eye", "brain") by screen readers.
 */
const steps = [
    {
        icon: EyeIcon,
        title: 'We read your website',
        body: 'Just enter your URL. We pick up your colours, fonts, and brand voice automatically — no forms to fill in.',
    },
    {
        icon: MagnifyingGlassIcon,
        title: 'We find your competitors',
        body: 'We look at who else is advertising in your space, check out their messaging, and figure out how to beat them.',
    },
    {
        icon: RocketLaunchIcon,
        title: 'Your ads run themselves',
        body: 'We fix disapproved ads, shift budget to what\'s working, and keep testing new ideas — every day, on autopilot.',
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
        body: 'Fixes any rejected ads and resubmits them, usually before you would have noticed.',
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

// Every figure here is stated elsewhere on this page: six agents, four
// platforms in the FAQ, and "live within minutes" against the 2–4 weeks the
// pricing page attributes to an agency.
const stats = [
    { value: 'Six', label: 'AI specialists per account', detail: 'working daily, not weekly' },
    { value: 'Four', label: 'ad platforms, one dashboard', detail: 'Google, Meta, Microsoft, LinkedIn' },
    { value: 'Minutes', label: 'from URL to live campaign', detail: 'an agency takes two to four weeks' },
];

export default function Landing({ auth, plans = [], meta = {} }) {
    const pageMeta = { ...DEFAULT_META, ...meta };
    const paidPlans = plans.filter(p => p.price_cents > 0 && !p.is_free);
    const lowestPrice = paidPlans.length > 0 ? Math.round(Math.min(...paidPlans.map(p => p.price_cents)) / 100) : 149;

    const [url, setUrl] = useState('');
    const [firstName, setFirstName] = useState('');
    const [email, setEmail] = useState('');
    const [loadingStage, setLoadingStage] = useState(0);
    const [demoResult, setDemoResult] = useState(null);
    const [error, setError] = useState(null);

    const normaliseUrl = (raw) => {
        const trimmed = raw.trim();
        if (!trimmed) return trimmed;
        if (/^https?:\/\//i.test(trimmed)) return trimmed;
        return 'https://' + trimmed;
    };

    const handleDemoSubmit = async (e) => {
        e.preventDefault();
        if (!url) return;
        const normalisedUrl = normaliseUrl(url);

        setError(null);
        setDemoResult(null);
        setLoadingStage(1);

        const t1 = setTimeout(() => setLoadingStage(2), 5000);
        const t2 = setTimeout(() => setLoadingStage(3), 10000);
        const t3 = setTimeout(() => setLoadingStage(4), 18000);

        try {
            const response = await fetch('/api/demo/generate-full', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ url: normalisedUrl, first_name: firstName, email })
            });
            const data = await response.json();

            if (response.ok) {
                setDemoResult(data);
                trackConversion('try_now');
            } else {
                setError(data.message || 'Something went wrong.');
            }
        } catch (err) {
            setError('Failed to reach the server.');
        } finally {
            clearTimeout(t1);
            clearTimeout(t2);
            clearTimeout(t3);
            setLoadingStage(0);
        }
    };

    const getLoadingText = () => {
        switch (loadingStage) {
            case 1: return "Reading your website...";
            case 2: return "Picking up your colours, fonts, and brand feel...";
            case 3: return "Understanding what makes your brand stand out...";
            case 4: return "Writing your ads...";
            default: return "Working on it...";
        }
    };

    const demoForm = (
        <div className="rounded-xl border border-gray-200 bg-white p-4 text-left shadow-lg">
            <form onSubmit={handleDemoSubmit} className="flex flex-col gap-3">
                <div className="flex flex-col gap-3 sm:flex-row">
                    <label htmlFor="demo-first-name" className="sr-only">First name</label>
                    <input
                        id="demo-first-name"
                        type="text"
                        required
                        value={firstName}
                        onChange={e => setFirstName(e.target.value)}
                        placeholder="First name"
                        className="flex-1 rounded-md border-gray-300 py-3 shadow-sm focus:border-brand-dark focus:ring-brand-dark"
                        disabled={loadingStage > 0}
                    />
                    <label htmlFor="demo-email" className="sr-only">Your email address</label>
                    <input
                        id="demo-email"
                        type="email"
                        required
                        value={email}
                        onChange={e => setEmail(e.target.value)}
                        placeholder="Your email address"
                        className="flex-1 rounded-md border-gray-300 py-3 shadow-sm focus:border-brand-dark focus:ring-brand-dark"
                        disabled={loadingStage > 0}
                    />
                </div>
                <div className="flex flex-col gap-3 sm:flex-row">
                    <label htmlFor="demo-url" className="sr-only">Your website URL</label>
                    <input
                        id="demo-url"
                        type="text"
                        required
                        value={url}
                        onChange={e => setUrl(e.target.value)}
                        placeholder="Enter your website URL..."
                        className="flex-1 rounded-md border-gray-300 py-3 shadow-sm focus:border-brand-dark focus:ring-brand-dark"
                        disabled={loadingStage > 0}
                    />
                    {/*
                        This submit is the hero's primary action, so it carries the
                        shared CTA treatment rather than a locally written orange that
                        drifts from it. Disabled is a grey fill at 6.10:1 rather than
                        the old opacity-50, which faded the whole button to 2.2:1 —
                        and "Analyzing..." is the visitor's only signal that the
                        20-second demo request is actually running.
                    */}
                    <button
                        type="submit"
                        disabled={loadingStage > 0}
                        className={`${CTA_PRIMARY} ${CTA_SIZE_COMPACT} w-full whitespace-nowrap disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-600 sm:w-auto`}
                    >
                        {loadingStage > 0 ? 'Analyzing...' : 'Build my ads'}
                    </button>
                </div>
            </form>
            {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
            {loadingStage > 0 && (
                <div className="mt-4">
                    <div className="h-2.5 w-full rounded-full bg-gray-200">
                        <div className="h-2.5 rounded-full bg-brand-dark transition-all duration-500" style={{ width: `${(loadingStage / 4) * 100}%` }}></div>
                    </div>
                    <p className="mt-2 animate-pulse text-center text-sm font-medium text-brand-darker">{getLoadingText()}</p>
                </div>
            )}
        </div>
    );

    return (
        <>
            <Head>
                {/*
                    Title and description come from LandingController so the server
                    HTML and the client-side head cannot disagree. They used to be
                    written twice — once here and once in the controller — and the
                    copy here was the one crawlers ended up with: 63 characters of
                    title after Inertia appended the app name, and a 175-character
                    description. Both were truncated in search results.

                    Open Graph and Twitter tags are rendered server-side in
                    app.blade.php, because the crawlers that read them do not run
                    JavaScript. Repeating them here only created a second copy to
                    keep in sync.
                */}
                <title>{pageMeta.title}</title>
                <meta name="description" content={pageMeta.description} />
                <script type="application/ld+json">{JSON.stringify({
                    "@context": "https://schema.org",
                    "@graph": [
                        {
                            "@type": "Organization",
                            "name": "sitetospend",
                            "url": "https://sitetospend.com",
                            "logo": "https://sitetospend.com/og-image.png",
                            "description": "AI-powered digital advertising platform with autonomous agents that manage and optimize ad campaigns across Google, Facebook, Microsoft, and LinkedIn.",
                            "sameAs": [
                                "https://www.linkedin.com/company/sitetospend"
                            ]
                        },
                        {
                            "@type": "SoftwareApplication",
                            "name": "sitetospend",
                            "applicationCategory": "BusinessApplication",
                            "operatingSystem": "Web",
                            "url": "https://sitetospend.com",
                            "description": "Autonomous AI agents that create, manage, and optimize digital ad campaigns across Google Ads, Facebook Ads, Microsoft Ads, and LinkedIn Ads.",
                            "offers": {
                                "@type": "AggregateOffer",
                                "lowPrice": lowestPrice,
                                "highPrice": "249",
                                "priceCurrency": "USD",
                                "offerCount": paidPlans.length
                            },
                            "featureList": [
                                "AI Competitor Discovery",
                                "Self-Optimising Campaigns",
                                "Budget Intelligence",
                                "Creative A/B Testing",
                                "Audience Intelligence",
                                "Vision AI Brand Extraction"
                            ]
                        },
                        {
                            "@type": "FAQPage",
                            "mainEntity": faqs.map((faq) => ({
                                "@type": "Question",
                                "name": faq.question,
                                "acceptedAnswer": {
                                    "@type": "Answer",
                                    "text": faq.answer
                                }
                            }))
                        }
                    ]
                })}</script>
            </Head>
            <div className="min-h-screen bg-white">
                <Header auth={auth} />

                <main>
                    {demoResult ? (
                        <div
                            className="bg-white px-4 py-10 sm:px-6 lg:px-8"
                            style={{ backgroundImage: `linear-gradient(to bottom, ${brandTint(10)}, #fff)` }}
                        >
                            <div className="mx-auto max-w-7xl">
                                <DemoResultsPanel result={demoResult} />
                            </div>
                        </div>
                    ) : (
                        <Hero
                            eyebrow="AI-powered ad campaign management"
                            headline={
                                /*
                                    The trailing space is deliberate and must stay inside a JSX
                                    expression to survive. These are block spans so it is invisible,
                                    but textContent concatenates them — without it the H1 reads
                                    "Managementwith the Power of AI" to crawlers and screen readers.
                                */
                                <>
                                    <span className="block">{'Automated Google & Meta ads management '}</span>
                                    <span className="block text-brand-darker">with the power of AI</span>
                                </>
                            }
                            sub="Stop paying agency retainer fees. Our AI spots your competitors, fixes broken ads, moves budget to what's working, and keeps testing new ideas—every single day, without you lifting a finger."
                            secondaryCta={{ href: '/how-it-works', label: 'See how it works' }}
                            note="No credit card required · Free to explore · Cancel anytime"
                        >
                            {demoForm}
                        </Hero>
                    )}

                    <StatsStrip items={stats} />

                    {/* Social Proof */}
                    <div className="border-b border-gray-200 bg-white py-12">
                        <div className="mx-auto max-w-7xl px-6 lg:px-8">
                            <p className="text-center text-sm font-semibold uppercase tracking-wider text-gray-500">Trusted by leading brands</p>
                            {/*
                                gray-400 is 2.54:1 on white. These are the only names on the
                                page a visitor is meant to recognise, so they are gray-600
                                (7.56:1) rather than a decorative grey.
                            */}
                            <div className="mt-8 flex flex-wrap items-center justify-center gap-x-10 gap-y-4">
                                <a href="https://proveably.com" target="_blank" rel="noopener noreferrer" className="font-semibold text-gray-600 transition-colors hover:text-gray-900">Proveably</a>
                                <a href="https://papsnap.com" target="_blank" rel="noopener noreferrer" className="font-semibold text-gray-600 transition-colors hover:text-gray-900">PapSnap</a>
                                <a href="https://yourfirststore.com" target="_blank" rel="noopener noreferrer" className="font-semibold text-gray-600 transition-colors hover:text-gray-900">YourFirstStore</a>
                                <a href="https://zonely.co" target="_blank" rel="noopener noreferrer" className="font-semibold text-gray-600 transition-colors hover:text-gray-900">Zonely</a>
                                <a href="https://firstdigital.co.nz" target="_blank" rel="noopener noreferrer" className="font-semibold text-gray-600 transition-colors hover:text-gray-900">First Digital</a>
                            </div>
                        </div>
                    </div>

                    <FeatureGrid
                        title="Up and running in 3 steps"
                        sub="We handle the hard parts. You focus on your business."
                        items={steps}
                        background="white"
                        footer={
                            <CtaLink href="/how-it-works" className="font-semibold text-brand-darker hover:underline">
                                Learn more about how it works
                            </CtaLink>
                        }
                    />

                    <FeatureGrid
                        eyebrow="Always working for you"
                        title="Your 24/7 AI PPC campaign manager"
                        sub="Six AI specialists, each focused on a different part of your advertising. Running around the clock, whether you're in a meeting or fast asleep."
                        items={agents}
                        footer={
                            <div className="flex flex-col items-center justify-center gap-4 sm:flex-row">
                                <CtaLink href="/register" className={`${CTA_PRIMARY} ${CTA_SIZE}`}>Put these agents to work</CtaLink>
                                <CtaLink href="/features" className={`${CTA_SECONDARY} ${CTA_SIZE}`}>See all features</CtaLink>
                            </div>
                        }
                    />

                    {/* Pricing teaser */}
                    <PricingTable
                        title="Simple, honest pricing"
                        sub={`Agency-quality results. Starting at just $${lowestPrice}/month.`}
                        plans={plans.map((plan) => ({
                            id: plan.id,
                            name: plan.name,
                            description: plan.description,
                            badge: plan.badge_text,
                            highlighted: plan.is_popular,
                            price: <PlanPrice plan={plan} />,
                        }))}
                        footnote={
                            <CtaLink href="/pricing" className={`${CTA_PRIMARY} ${CTA_SIZE}`}>
                                Compare plans
                            </CtaLink>
                        }
                    />

                    {/* Why It Works */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                            <h2 className="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                                Automated Ad Management That Drives ROI
                            </h2>
                            <div className="mt-6 space-y-5 text-lg leading-relaxed text-gray-600">
                                <p>
                                    Most small advertisers lose money in the gaps rather than the strategy. A headline gets
                                    disapproved on a Friday and nobody notices until Monday. A keyword that converted well in
                                    March quietly stops working in June. Budget sits in an ad group that has not produced a
                                    lead in weeks, because moving it means opening the account and nobody has the time.
                                </p>
                                <p>
                                    Those gaps are exactly what an agency retainer is meant to cover, and exactly what a
                                    retainer is worst at covering — a person reviewing your account once a week will always be
                                    six days behind the auction. Our agents work the other way round. They look at every
                                    campaign, ad group, keyword and creative every single day, and when something needs
                                    changing they change it rather than adding it to a report you will read next month.
                                </p>
                                <p>
                                    The result is not magic, it is attention. Disapprovals get fixed within hours. Budget moves
                                    toward the searches that convert while the intent is still there. Losing creative gets
                                    replaced before it has drained a week of spend. That is most of what good ad management
                                    actually is, and it turns out to be something software does more reliably than a calendar
                                    reminder.
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* FAQ */}
                    <div className="border-t border-gray-200 bg-gray-50 py-16 sm:py-24">
                        <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                            <h2 className="text-center text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                                Common questions
                            </h2>
                            <p className="mt-4 text-center text-lg text-gray-600">
                                The things people ask before they sign up, answered properly.
                            </p>
                            <dl className="mt-12 space-y-10">
                                {faqs.map((faq) => (
                                    <div key={faq.question}>
                                        <dt>
                                            <h3 className="text-xl font-semibold text-gray-900">{faq.question}</h3>
                                        </dt>
                                        <dd className="mt-3 text-base leading-relaxed text-gray-600">{faq.answer}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    </div>

                    <CtaBand
                        title="Ready to stop doing this the hard way?"
                        body="Join hundreds of businesses who handed the heavy lifting to their AI team—and haven't looked back."
                        primaryCta={{ href: '/register', label: 'Get started free' }}
                        secondaryCta={{ href: '/login', label: 'Sign in' }}
                        note="Free to explore · No credit card required · Live in minutes"
                    />
                </main>

                <Footer />
            </div>
        </>
    );
}
