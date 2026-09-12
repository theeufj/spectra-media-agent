import React, { useEffect } from 'react';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import Hero from '@/Components/Marketing/Hero';
import PricingTable, { PlanPrice } from '@/Components/Marketing/PricingTable';
import CtaBand from '@/Components/Marketing/CtaBand';
import FaqAccordion from '@/Components/Marketing/FaqAccordion';
import SetupOnlyOffer from '@/Components/Marketing/SetupOnlyOffer';
import { BoltIcon, LockClosedIcon, ShieldCheckIcon } from '@heroicons/react/24/outline';
import { trackConversion } from '@/utils/conversions';

const trustSeals = [
    { icon: LockClosedIcon, label: 'Secure Stripe payment' },
    { icon: BoltIcon, label: 'Instant campaign deployment' },
    { icon: ShieldCheckIcon, label: 'Data encrypted and private' },
];

/*
 * No <Head> here — see the note in Landing.jsx. LandingController::pricing
 * owns this page's title, description, Open Graph pair and FAQPage schema.
 */
export default function Pricing({ auth, plans = [], faqs = [], setupFeeUsd = 999 }) {
    useEffect(() => { trackConversion('pricing_visit'); }, []);

    return (
        <>
            <PageTitle />
            <div className="min-h-screen bg-white">
                <Header auth={auth} />

                <main>
                    <Hero
                        eyebrow="Pricing"
                        headline="Simple, transparent pricing"
                        // "Generous limits" against a free plan allowing one campaign,
                        // one brand extraction and four images — and showing zero
                        // videos and zero refinements in-app. The promise and the
                        // product contradicted each other at the moment of signup.
                        sub="Free covers one campaign, your brand extracted from your site, and four AI images — enough to see what it produces. Upgrade when you're ready to run live."
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
                                            /*
                                                US$149 is Starter, the cheapest paid plan, read
                                                from production. I briefly changed this to US$99
                                                on the strength of the local dev seed, which has
                                                a stale Starter price — and the plan cards below
                                                render price_cents from the live database, so the
                                                page would have contradicted itself one section
                                                apart. Check `plans` in sitetospend_prod, not the
                                                dev database, before touching this figure.
                                            */
                                            ['Cost', 'US$2,500 - US$5,000 / month', 'From US$149 / month'],
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
                            <div className="mx-auto max-w-3xl space-y-10">
                                <div className="flex flex-col items-center justify-center gap-4 text-sm text-gray-600 sm:flex-row sm:gap-8">
                                    {trustSeals.map((seal) => (
                                        <span key={seal.label} className="flex items-center gap-2">
                                            <seal.icon className="h-5 w-5 text-brand-dark" aria-hidden="true" />
                                            {seal.label}
                                        </span>
                                    ))}
                                </div>
                                <SetupOnlyOffer priceUsd={setupFeeUsd} />
                            </div>
                        }
                    />

                    {/* FAQ */}
                    <div className="bg-white py-12 sm:py-24">
                        <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                            <div className="mb-8 text-center sm:mb-12">
                                <h2 className="text-3xl font-extrabold text-gray-900 sm:text-4xl">Frequently Asked Questions</h2>
                                <p className="mt-3 text-base text-gray-600 sm:mt-4 sm:text-lg">How sitetospend works, answered.</p>
                            </div>

                            <FaqAccordion items={faqs} />
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
