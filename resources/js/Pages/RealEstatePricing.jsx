import React from 'react';
import { Head, Link } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

const faqs = [
    {
        question: "What does the $1,000 setup fee cover?",
        answer: "The setup fee covers everything to get your property live — we read your listing, write the ad copy, set up keyword targeting for the right buyers in your area, connect your Google Ads account, and launch the campaign. Your listing is ready to attract buyers from day one.",
    },
    {
        question: "When does the $500/month billing start?",
        answer: "The monthly fee kicks in 30 days after your campaign launches. It covers ongoing AI optimisation — daily budget adjustments, bid management, new keyword opportunities, and weekly performance reports — until the property settles.",
    },
    {
        question: "What happens when the property sells?",
        answer: "Let us know and we pause the campaign immediately. No lock-in, no cancellation fees. You only pay for the months the campaign is active.",
    },
    {
        question: "Does this include the ad spend budget?",
        answer: "No. The platform fee is separate from your Google Ads budget. Your ad spend goes directly to Google — we never touch it or mark it up. We recommend a minimum of $20/day in ad spend per property for meaningful results.",
    },
    {
        question: "Can I run campaigns for multiple properties at once?",
        answer: "Yes. Each property gets its own campaign with its own $1,000 setup and $500/month ongoing. Discounts are available for agents managing 5 or more active listings — contact us to discuss.",
    },
    {
        question: "What if I want to pause a campaign temporarily?",
        answer: "You can pause anytime from your dashboard. The monthly billing pauses too — you won't be charged for months the campaign is inactive.",
    },
];

export default function RealEstatePricing({ auth }) {
    const [openFAQ, setOpenFAQ] = React.useState(null);

    return (
        <>
            <Head>
                <title>Pricing — Real Property Ads</title>
                <meta name="description" content="One simple package for real estate agents. $1,000 to launch your property campaign, then $500/month until it sells. No lock-in." />
            </Head>

            <div className="min-h-screen bg-gray-50 text-gray-800">
                <Header auth={auth} />

                <main>
                    {/* Hero */}
                    <div className="bg-gradient-to-b from-brand-primary/10 to-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center">
                            <p className="text-sm font-semibold text-brand-primary uppercase tracking-wider">Pricing</p>
                            <h1 className="mt-3 text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900">
                                One package. No lock-in.
                            </h1>
                            <p className="mt-6 max-w-2xl mx-auto text-lg text-gray-500">
                                A single straightforward price per property — you only keep paying while it's on the market.
                            </p>
                        </div>
                    </div>

                    {/* Pricing card */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="max-w-lg mx-auto px-4 sm:px-6 lg:px-8">
                            <div className="rounded-2xl border-2 border-brand-primary bg-white shadow-xl overflow-hidden">
                                {/* Card header */}
                                <div className="bg-brand-primary px-8 py-8 text-white text-center">
                                    <p className="text-sm font-semibold uppercase tracking-widest text-white/70">Per Property</p>
                                    <h2 className="mt-2 text-3xl font-bold">Property Campaign</h2>
                                    <p className="mt-2 text-white/80">Everything you need to sell faster</p>
                                </div>

                                {/* Pricing */}
                                <div className="px-8 py-8 border-b border-gray-100">
                                    <div className="flex items-end gap-4">
                                        <div className="flex-1 text-center p-4 rounded-xl bg-gray-50">
                                            <p className="text-sm font-medium text-gray-500 mb-1">Launch fee</p>
                                            <p className="text-4xl font-extrabold text-gray-900">$1,000</p>
                                            <p className="text-sm text-gray-500 mt-1">one-time per listing</p>
                                        </div>
                                        <div className="text-2xl font-light text-gray-400 pb-4">+</div>
                                        <div className="flex-1 text-center p-4 rounded-xl bg-brand-primary/5 border border-brand-primary/20">
                                            <p className="text-sm font-medium text-brand-primary mb-1">Monthly</p>
                                            <p className="text-4xl font-extrabold text-brand-primary">$500</p>
                                            <p className="text-sm text-brand-primary/70 mt-1">until property sells</p>
                                        </div>
                                    </div>
                                    <p className="mt-4 text-center text-sm text-gray-400">
                                        + your Google Ads budget (goes directly to Google, recommended min. $20/day)
                                    </p>
                                </div>

                                {/* Features */}
                                <div className="px-8 py-8">
                                    <p className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">What's included</p>
                                    <ul className="space-y-3">
                                        {[
                                            'AI-written ad copy tailored to your listing',
                                            'Google Search & Display campaign setup',
                                            'Hyper-local buyer targeting by suburb & price range',
                                            'Seller retargeting audiences',
                                            'Daily automated bid & budget optimisation',
                                            'Weekly performance reports emailed to you',
                                            'Rejected ad auto-fix & resubmission',
                                            'Pause or cancel anytime — no penalty',
                                        ].map((feature) => (
                                            <li key={feature} className="flex items-start gap-3">
                                                <svg className="h-5 w-5 text-brand-accent flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                                </svg>
                                                <span className="text-gray-700 text-sm">{feature}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>

                                {/* CTA */}
                                <div className="px-8 pb-8">
                                    <a
                                        href="/register"
                                        className="block w-full text-center rounded-xl px-6 py-4 text-base font-semibold text-white bg-brand-primary hover:bg-brand-dark transition-colors shadow-md"
                                    >
                                        Launch your first property campaign
                                    </a>
                                    <p className="mt-3 text-center text-xs text-gray-400">
                                        No credit card required to get started
                                    </p>
                                </div>
                            </div>

                            {/* Volume note */}
                            <div className="mt-6 text-center p-4 rounded-xl bg-brand-accent/10 border border-brand-accent/30">
                                <p className="text-sm font-medium text-gray-700">
                                    Managing 5+ listings?
                                </p>
                                <p className="text-sm text-gray-500 mt-1">
                                    Contact us for volume pricing —{' '}
                                    <a href="mailto:hello@realpropertyads.com" className="text-brand-primary hover:underline font-medium">
                                        hello@realpropertyads.com
                                    </a>
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Comparison */}
                    <div className="bg-gray-50 py-16 sm:py-24">
                        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                            <h2 className="text-3xl font-bold text-gray-900 text-center mb-12">
                                vs. hiring a real estate marketing agency
                            </h2>
                            <div className="overflow-hidden rounded-xl border border-gray-200 shadow-sm">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/3"></th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Traditional Agency</th>
                                            <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase tracking-wider">Real Property Ads</th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {[
                                            ['Monthly cost', '$2,000 – $5,000 retainer', '$500/listing'],
                                            ['Setup time', '2 – 4 weeks', '< 5 minutes'],
                                            ['Ad copy', 'Generic templates', 'Written from your listing'],
                                            ['Optimisation', 'Weekly manual check', 'Every day, automated'],
                                            ['Reporting', 'Monthly PDF', 'Weekly email report'],
                                            ['Lock-in contract', 'Usually 3 – 6 months', 'None — cancel anytime'],
                                        ].map(([label, agency, ours]) => (
                                            <tr key={label}>
                                                <td className="px-6 py-4 text-sm font-medium text-gray-900">{label}</td>
                                                <td className="px-6 py-4 text-sm text-gray-500">{agency}</td>
                                                <td className="px-6 py-4 text-sm font-semibold text-brand-primary">{ours}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    {/* FAQ */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                            <h2 className="text-3xl font-extrabold text-gray-900 text-center mb-10">
                                Frequently Asked Questions
                            </h2>
                            <div className="space-y-4">
                                {faqs.map((faq, index) => (
                                    <div key={index} className="border border-gray-200 rounded-lg">
                                        <button
                                            onClick={() => setOpenFAQ(openFAQ === index ? null : index)}
                                            className="w-full px-6 py-4 text-left flex items-center justify-between hover:bg-gray-50 transition-colors"
                                        >
                                            <h3 className="text-base font-semibold text-gray-900 pr-4">{faq.question}</h3>
                                            <svg className={`h-5 w-5 text-brand-primary flex-shrink-0 transition-transform ${openFAQ === index ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>
                                        {openFAQ === index && (
                                            <div className="px-6 py-4 border-t border-gray-200 bg-gray-50">
                                                <p className="text-gray-600 text-sm leading-relaxed">{faq.answer}</p>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* CTA */}
                    <div className="bg-brand-primary py-16 sm:py-24">
                        <div className="max-w-3xl mx-auto text-center px-4 sm:px-6 lg:px-8">
                            <h2 className="text-3xl sm:text-4xl font-extrabold text-white">
                                Ready to get more eyes on your listing?
                            </h2>
                            <p className="mt-4 text-lg text-white/80">
                                Launch a campaign in under 5 minutes. Only pay while the property is on the market.
                            </p>
                            <div className="mt-8 flex flex-col sm:flex-row gap-4 justify-center">
                                <a
                                    href="/register"
                                    className="inline-flex items-center justify-center px-8 py-4 rounded-lg text-lg font-semibold text-brand-primary bg-white hover:bg-gray-50 shadow-lg"
                                >
                                    Start your first campaign
                                </a>
                                <a
                                    href="mailto:hello@realpropertyads.com"
                                    className="inline-flex items-center justify-center px-8 py-4 rounded-lg text-lg font-semibold text-white border-2 border-white/60 hover:border-white"
                                >
                                    Talk to us first
                                </a>
                            </div>
                        </div>
                    </div>
                </main>

                <Footer />
            </div>
        </>
    );
}
