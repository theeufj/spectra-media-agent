import React, { useEffect } from 'react';
import { Head } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import Hero from '@/Components/Marketing/Hero';
import PricingTable, { PlanPrice } from '@/Components/Marketing/PricingTable';
import CtaBand from '@/Components/Marketing/CtaBand';
import { BoltIcon, ChevronDownIcon, LockClosedIcon, ShieldCheckIcon } from '@heroicons/react/24/outline';
import { trackConversion } from '@/utils/conversions';

const trustSeals = [
    { icon: LockClosedIcon, label: 'Secure Stripe payment' },
    { icon: BoltIcon, label: 'Instant campaign deployment' },
    { icon: ShieldCheckIcon, label: 'Data encrypted and private' },
];

export default function Pricing({ auth, plans = [] }) {
    const [openFAQ, setOpenFAQ] = React.useState(null);
    useEffect(() => { trackConversion('pricing_visit'); }, []);

    const faqs = [
        { question: "What's included in the free tier?", answer: "The free tier lets you properly kick the tyres before you commit. You get 3 website or file sources for brand matching, 4 AI-generated images per campaign (with a watermark), 3 landing page audits, and unlimited ad copy. Going live on Google or Facebook requires a paid plan—upgrade whenever you're ready." },
        { question: "Does the subscription price include my ad budget?", answer: "No, and that's intentional. Your subscription pays for the platform and the AI doing the work. Your actual ad spend goes straight to Google, Facebook, and the other networks—we never touch it or mark it up. You always see exactly where every dollar goes." },
        { question: "How does ad spend billing work?", answer: "When you launch your first campaign, we load up 7 days' worth of estimated spend as a credit balance. Each morning at 6 AM we deduct the previous day's actual spend. When your balance starts running low, we top it up automatically so your campaigns never go dark unexpectedly." },
        { question: "What happens if a payment fails?", answer: "We give you 24 hours to sort it out—no immediate panic. If it's still unresolved after that, we trim budgets by 50% to slow spend. After another day we'll pause everything to make sure nobody's out of pocket. The moment your payment goes through, everything picks straight back up at full speed." },
        { question: "How do I update my payment method?", answer: "Head to Billing → Ad Spend in your dashboard. If a payment has failed you'll also see a 'Retry Payment' button—tap that after updating your card and we'll charge it right away and get your campaigns back up." },
        { question: "What do the AI specialists actually do?", answer: "Six of them, running 24/7. One finds your competitors. One digs into their websites to find angles you can use. One fixes any rejected ads before you even notice. One moves your budget to the hours your customers are most active. One tests different ad variations and keeps the winners. One finds new people who look like your existing customers. Together they do the work of a full marketing team." },
        { question: "How does competitor analysis work?", answer: "Every week we read your website to understand your business, then look at who else is advertising in your space. We check out their messaging, their offers, and what they're saying—then figure out the best way for you to stand out. You don't have to ask, it just happens." },
        { question: "What happens if my ad gets disapproved?", answer: "We catch it automatically, rewrite it so it passes Google's checks, and resubmit it—all without you needing to do anything. Ads that simply stop performing get paused before they waste more of your budget." },
        { question: 'How does it know what my brand looks like?', answer: "We take a screenshot of your website and our AI reads it—your colours, your fonts, your tone. Every ad we create matches your look without you needing to fill in a single form or upload a brand guide." },
        { question: "I already have a Google Ads account — can you use it?", answer: "Yes, and it's the fastest way to start. Give us your 10-digit Google Ads customer ID and we'll send a manager request to that account. You approve it inside Google Ads (Admin → Access and security → Managers), and we can build campaigns straight away. Your billing stays with Google on your own payment method, and you can revoke our access at any time from the same screen." },
        { question: "What if I don't have a Google Ads account yet?", answer: "We'll walk you through creating one. Linking an account you already own is quicker, so if you've ever run ads — even years ago — it's worth digging out that login first." },
        { question: "What access do you actually get to my ad account?", answer: "Manager access, which lets us create and optimise campaigns. We never touch your billing: ad spend goes directly from you to Google, and we take no percentage of it. Remove our access whenever you like and your account and its history stay entirely yours." },
        { question: "Can I switch plans later?", answer: "Absolutely. Upgrade or downgrade anytime from your dashboard. If you ever hit the limits of your plan we'll let you know before anything stops working." },
    ];

    return (
        <>
            <Head>
                <title>Pricing - Simple, Transparent Plans | sitetospend</title>
                <meta name="description" content="sitetospend pricing: Starter at $149/mo, Growth at $249/mo, Agency priced on application. All plans include AI agents, campaign automation, and a 7-day free trial. No credit card required." />
                <meta property="og:title" content="Pricing — AI Ad Management from $149/mo | sitetospend" />
                <meta property="og:description" content="Starter $149/mo, Growth $249/mo, Agency on application. AI agents, campaign automation, and a free trial. No credit card required." />
                <meta name="twitter:title" content="Pricing — AI Ad Management from $149/mo | sitetospend" />
                <meta name="twitter:description" content="Starter $149/mo, Growth $249/mo, Agency on application. AI agents, campaign automation, and a free trial." />
                <script type="application/ld+json">{JSON.stringify({
                    "@context": "https://schema.org",
                    "@type": "FAQPage",
                    "mainEntity": faqs.map(faq => ({
                        "@type": "Question",
                        "name": faq.question,
                        "acceptedAnswer": {
                            "@type": "Answer",
                            "text": faq.answer
                        }
                    }))
                })}</script>
            </Head>
            <div className="min-h-screen bg-white">
                <Header auth={auth} />

                <main>
                    <Hero
                        eyebrow="Pricing"
                        headline="Simple, transparent pricing"
                        sub="Try free with generous limits. Upgrade when you're ready to deploy live campaigns."
                    />

                    {/* Comparison Table */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="mb-12 text-center">
                                <h2 className="text-3xl font-bold text-gray-900">Why Choose AI Over a Traditional Agency?</h2>
                            </div>
                            {/*
                                overflow-x-auto, not overflow-hidden: three columns of
                                whitespace-nowrap cells do not fit a phone, and hidden
                                simply clipped the sitetospend column off the right edge.
                            */}
                            <div className="mx-auto max-w-4xl overflow-x-auto rounded-xl border border-gray-200 shadow-sm">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"><span className="sr-only">Comparison</span></th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Traditional Agency</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-brand-dark">sitetospend AI</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200 bg-white">
                                        {[
                                            ['Cost', '$2,500 - $5,000 / month', 'From $149 / month'],
                                            ['Setup Time', '2-4 Weeks', '< 5 Minutes'],
                                            ['Brand Matching', 'Manual PDF creation (billed extra)', 'Reads your website automatically'],
                                            ['Ad Creative', 'Limited revisions, extra cost', 'AI writes and generates images for you'],
                                            ['Optimisation', 'Weekly manual checks', 'Every hour, automatically'],
                                            ['Competitor Research', 'Manual (charged by the hour)', 'Automatic weekly discovery (Growth plan)'],
                                            ['Rejected Ads', 'Wait for account manager', 'Fixed and resubmitted automatically'],
                                        ].map(([label, agency, ai]) => (
                                            <tr key={label}>
                                                <th scope="row" className="whitespace-nowrap px-6 py-4 text-left text-sm font-medium text-gray-900">{label}</th>
                                                <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{agency}</td>
                                                <td className="whitespace-nowrap px-6 py-4 text-sm font-bold text-brand-dark">{ai}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <PricingTable
                        background="gray"
                        plans={plans.map((plan) => ({
                            id: plan.id,
                            name: plan.name,
                            description: plan.description,
                            badge: plan.badge_text,
                            highlighted: plan.is_popular,
                            price: <PlanPrice plan={plan} />,
                            features: plan.features || [],
                            note: plan.slug === 'starter'
                                ? 'You pick your platform (Google or Facebook) when you sign up.'
                                : null,
                            cta: {
                                href: plan.cta_text === 'Contact Sales'
                                    ? 'mailto:hello@sitetospend.com?subject=Agency Plan Inquiry'
                                    : '/register',
                                label: plan.cta_text || 'Get Started',
                            },
                        }))}
                        footnote={
                            <div className="flex flex-col items-center justify-center gap-4 text-sm text-gray-600 sm:flex-row sm:gap-8">
                                {trustSeals.map((seal) => (
                                    <span key={seal.label} className="flex items-center gap-2">
                                        <seal.icon className="h-5 w-5 text-brand-dark" aria-hidden="true" />
                                        {seal.label}
                                    </span>
                                ))}
                            </div>
                        }
                    />

                    {/* FAQ */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                            <div className="mb-12 text-center">
                                <h2 className="text-3xl font-extrabold text-gray-900 sm:text-4xl">Frequently Asked Questions</h2>
                                <p className="mt-4 text-lg text-gray-600">Get answers to common questions about how sitetospend works.</p>
                            </div>

                            <div className="space-y-4">
                                {faqs.map((faq, index) => (
                                    <div key={faq.question} className="rounded-lg border border-gray-200">
                                        <h3>
                                            <button
                                                type="button"
                                                onClick={() => setOpenFAQ(openFAQ === index ? null : index)}
                                                aria-expanded={openFAQ === index}
                                                aria-controls={`faq-answer-${index}`}
                                                className="flex w-full items-center justify-between px-6 py-4 text-left text-lg font-bold text-gray-900 transition-colors hover:bg-gray-50"
                                            >
                                                {faq.question}
                                                <ChevronDownIcon
                                                    className={`h-6 w-6 flex-shrink-0 text-brand-dark transition-transform ${openFAQ === index ? 'rotate-180' : ''}`}
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </h3>
                                        {openFAQ === index && (
                                            <div id={`faq-answer-${index}`} className="border-t border-gray-200 bg-gray-50 px-6 py-4">
                                                <p className="text-gray-600">{faq.answer}</p>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    <CtaBand
                        title="Ready to transform your marketing?"
                        body="Start creating smarter, faster campaigns with AI-powered optimization."
                        primaryCta={{ href: '/register', label: 'Get started free' }}
                        secondaryCta={{ href: '/features', label: 'Explore features' }}
                        note="Free to explore · No credit card required · Live in minutes"
                    />
                </main>

                <Footer />
            </div>
        </>
    );
}
