import React from 'react';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import Hero, { brandTint } from '@/Components/Marketing/Hero';
import PricingTable from '@/Components/Marketing/PricingTable';
import CtaBand from '@/Components/Marketing/CtaBand';
import FaqAccordion from '@/Components/Marketing/FaqAccordion';

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

/*
 * This skin prices per property rather than per month, which is why
 * PricingTable takes `price` as a node: the chrome, tick list, CTA and small
 * print are the flagship's, and only the figure block differs.
 */
const price = (
    <>
        <div className="flex items-end gap-4">
            <div className="flex-1 rounded-xl bg-gray-50 p-4 text-center">
                <p className="mb-1 text-sm font-medium text-gray-500">Launch fee</p>
                <p className="text-4xl font-extrabold text-gray-900">$1,000</p>
                <p className="mt-1 text-sm text-gray-500">one-time per listing</p>
            </div>
            <div className="pb-4 text-2xl font-light text-gray-500" aria-hidden="true">+</div>
            <div
                className="flex-1 rounded-xl border p-4 text-center"
                style={{ backgroundColor: brandTint(5), borderColor: brandTint(25) }}
            >
                {/* brand-darker on the 5% brand tint is 7.19:1; brand-primary would be 2.9:1. */}
                <p className="mb-1 text-sm font-medium text-brand-darker">Monthly</p>
                <p className="text-4xl font-extrabold text-brand-darker">$500</p>
                <p className="mt-1 text-sm text-gray-600">until property sells</p>
            </div>
        </div>
        <p className="mt-4 text-center text-sm text-gray-500">
            + your Google Ads budget (goes directly to Google, recommended min. $20/day)
        </p>
    </>
);

const features = [
    'AI-written ad copy tailored to your listing',
    'Google Search & Display campaign setup',
    'Hyper-local buyer targeting by suburb & price range',
    'Seller retargeting audiences',
    'Daily automated bid & budget optimisation',
    'Weekly performance reports emailed to you',
    'Rejected ad auto-fix & resubmission',
    'Pause or cancel anytime — no penalty',
];

/*
 * No <Head> here — LandingController serves this skin's own title, description
 * and Open Graph pair. It used to inherit the flagship's, so realpropertyads.com
 * advertised "| sitetospend" to every crawler that does not run JavaScript,
 * while the tags written here appended a second, different set on top.
 */
export default function RealEstatePricing({ auth }) {

    return (
        <>
            <PageTitle />
            <div className="min-h-screen bg-white">
                <Header auth={auth} />

                <main>
                    <Hero
                        eyebrow="Pricing"
                        headline="One package. No lock-in."
                        sub="A single straightforward price per property — you only keep paying while it's on the market."
                    />

                    <PricingTable
                        plans={[{
                            id: 'property-campaign',
                            eyebrow: 'Per property',
                            name: 'Property Campaign',
                            description: 'Everything you need to sell faster',
                            highlighted: true,
                            price,
                            features,
                            note: 'No credit card required to get started',
                            cta: { href: '/register', label: 'Launch your first property campaign' },
                        }]}
                        footnote={
                            <div
                                className="mx-auto max-w-lg rounded-xl border p-4"
                                style={{ backgroundColor: brandTint(15, 'accent'), borderColor: brandTint(40, 'accent') }}
                            >
                                <p className="text-sm font-medium text-gray-700">Managing 5+ listings?</p>
                                <p className="mt-1 text-sm text-gray-600">
                                    Contact us for volume pricing —{' '}
                                    <a href="mailto:hello@realpropertyads.com" className="font-medium text-brand-darker hover:underline">
                                        hello@realpropertyads.com
                                    </a>
                                </p>
                            </div>
                        }
                    />

                    {/* Comparison */}
                    <div className="bg-gray-50 py-16 sm:py-24">
                        <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                            <h2 className="mb-12 text-center text-3xl font-bold text-gray-900">
                                vs. hiring a real estate marketing agency
                            </h2>
                            <div className="overflow-x-auto rounded-xl border border-gray-200 shadow-sm">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="w-1/3 px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"><span className="sr-only">Comparison</span></th>
                                            <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Traditional Agency</th>
                                            <th className="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-brand-dark">Real Property Ads</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200 bg-white">
                                        {[
                                            ['Monthly cost', '$2,000 – $5,000 retainer', '$500/listing'],
                                            ['Setup time', '2 – 4 weeks', '< 5 minutes'],
                                            ['Ad copy', 'Generic templates', 'Written from your listing'],
                                            ['Optimisation', 'Weekly manual check', 'Every day, automated'],
                                            ['Reporting', 'Monthly PDF', 'Weekly email report'],
                                            ['Lock-in contract', 'Usually 3 – 6 months', 'None — cancel anytime'],
                                        ].map(([label, agency, ours]) => (
                                            <tr key={label}>
                                                <th scope="row" className="px-6 py-4 text-left text-sm font-medium text-gray-900">{label}</th>
                                                <td className="px-6 py-4 text-sm text-gray-500">{agency}</td>
                                                <td className="px-6 py-4 text-sm font-semibold text-brand-dark">{ours}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    {/* FAQ */}
                    <div className="bg-white py-12 sm:py-24">
                        <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                            <h2 className="mb-8 text-center text-3xl font-extrabold text-gray-900 sm:mb-10">
                                Frequently Asked Questions
                            </h2>
                            <FaqAccordion items={faqs} />
                        </div>
                    </div>

                    <CtaBand
                        title="Ready to get more eyes on your listing?"
                        body="Launch a campaign in under 5 minutes. Only pay while the property is on the market."
                        primaryCta={{ href: '/register', label: 'Start your first campaign' }}
                        secondaryCta={{ href: 'mailto:hello@realpropertyads.com', label: 'Talk to us first' }}
                    />
                </main>

                <Footer />
            </div>
        </>
    );
}
