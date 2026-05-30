import React from 'react';
import { Head } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

const agents = [
    {
        emoji: '📋',
        title: 'Listing Reader',
        description: 'Reads your property URL — bedrooms, price, suburb, features — and builds the campaign brief automatically.',
    },
    {
        emoji: '✍️',
        title: 'Ad Copywriter',
        description: 'Writes Google Search headlines and descriptions tailored to the property and your agency brand voice.',
    },
    {
        emoji: '🗺️',
        title: 'Location Targeter',
        description: 'Sets geographic targeting to the right suburbs, postcodes, and school districts for that listing\'s buyer profile.',
    },
    {
        emoji: '💰',
        title: 'Budget Optimiser',
        description: 'Shifts ad spend to the hours and days when property buyers are most active in your area.',
    },
    {
        emoji: '🔁',
        title: 'Ad Fixer',
        description: 'Catches any disapproved ads, rewrites them to pass Google\'s policy checks, and resubmits — without you lifting a finger.',
    },
    {
        emoji: '👥',
        title: 'Audience Builder',
        description: 'Creates retargeting lists from people who viewed the listing page, and lookalike audiences from your past buyers.',
    },
];

export default function RealEstateHowItWorks({ auth }) {
    return (
        <>
            <Head>
                <title>How It Works — Real Property Ads</title>
                <meta name="description" content="From listing URL to live Google Ads campaign in under 5 minutes. See how Real Property Ads works for real estate agents." />
            </Head>

            <div className="min-h-screen bg-gray-50 text-gray-800">
                <Header auth={auth} />

                <main>
                    {/* Hero */}
                    <div className="bg-gradient-to-b from-brand-primary/10 to-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center">
                            <p className="text-sm font-semibold text-brand-primary uppercase tracking-wider">How It Works</p>
                            <h1 className="mt-3 text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900">
                                Listing URL to live campaign<br />in under 5 minutes
                            </h1>
                            <p className="mt-6 max-w-2xl mx-auto text-lg text-gray-500">
                                Paste your property listing. Our AI does everything else — copy, targeting, launch, and daily optimisation.
                            </p>
                        </div>
                    </div>

                    {/* Steps */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

                            {/* Step 1 */}
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center mb-24">
                                <div>
                                    <div className="inline-flex items-center justify-center h-14 w-14 rounded-full bg-brand-primary/10 mb-6">
                                        <span className="text-3xl">🔗</span>
                                    </div>
                                    <h2 className="text-3xl font-bold text-gray-900 mb-4">1. Connect and paste your listing</h2>
                                    <p className="text-lg text-gray-600 leading-relaxed mb-6">
                                        Link your Google Ads account with one click. Then paste the URL of the property you want to advertise — your listing on realestate.com.au, Domain, or your own agency website.
                                    </p>
                                    <ul className="space-y-3">
                                        {[
                                            'One-click Google Ads connection',
                                            'Works with any listing URL',
                                            'No spreadsheets or forms to fill in',
                                            'Your agency branding applied automatically',
                                        ].map((item) => (
                                            <li key={item} className="flex items-center text-gray-600">
                                                <svg className="h-5 w-5 text-brand-accent mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                                </svg>
                                                {item}
                                            </li>
                                        ))}
                                    </ul>
                                    <div className="mt-8">
                                        <a href="/register" className="inline-flex items-center justify-center px-8 py-4 text-lg font-bold rounded-xl text-white bg-brand-primary hover:bg-brand-dark shadow-lg transition-colors">
                                            Get started →
                                        </a>
                                        <p className="mt-3 text-sm text-gray-400">No credit card required</p>
                                    </div>
                                </div>
                                <div className="bg-brand-primary/5 border border-brand-primary/20 rounded-2xl p-8 flex items-center justify-center min-h-[300px]">
                                    <div className="text-center">
                                        <span className="text-7xl">🔗</span>
                                        <p className="mt-4 text-brand-primary font-semibold">Paste the listing URL</p>
                                        <p className="text-sm text-gray-500 mt-2">realestate.com.au · Domain · your own site</p>
                                    </div>
                                </div>
                            </div>

                            {/* Step 2 */}
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center mb-24">
                                <div className="order-2 lg:order-1 bg-brand-primary/5 border border-brand-primary/20 rounded-2xl p-8 flex items-center justify-center min-h-[300px]">
                                    <div className="text-center">
                                        <span className="text-7xl">🤖</span>
                                        <p className="mt-4 text-brand-primary font-semibold">AI reads the listing</p>
                                        <p className="text-sm text-gray-500 mt-2">Bedrooms · price · suburb · features · photos</p>
                                    </div>
                                </div>
                                <div className="order-1 lg:order-2">
                                    <div className="inline-flex items-center justify-center h-14 w-14 rounded-full bg-brand-primary/10 mb-6">
                                        <span className="text-3xl">🤖</span>
                                    </div>
                                    <h2 className="text-3xl font-bold text-gray-900 mb-4">2. AI builds the campaign</h2>
                                    <p className="text-lg text-gray-600 leading-relaxed mb-6">
                                        Our AI reads every detail of the listing — bedrooms, price, suburb, key features — and writes ad copy, selects keywords, and sets targeting for the buyers most likely to enquire. Ready in seconds.
                                    </p>
                                    <ul className="space-y-3">
                                        {[
                                            'Ad headlines and descriptions written from the listing',
                                            'Keywords matched to the suburb, price range, and property type',
                                            'Buyer targeting by location, search intent, and device',
                                            'Retargeting audiences for people who viewed the listing',
                                        ].map((item) => (
                                            <li key={item} className="flex items-center text-gray-600">
                                                <svg className="h-5 w-5 text-brand-accent mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                                </svg>
                                                {item}
                                            </li>
                                        ))}
                                    </ul>
                                    <div className="mt-8">
                                        <a href="/register" className="inline-flex items-center justify-center px-8 py-4 text-lg font-bold rounded-xl text-white bg-brand-primary hover:bg-brand-dark shadow-lg transition-colors">
                                            Get started →
                                        </a>
                                        <p className="mt-3 text-sm text-gray-400">No credit card required</p>
                                    </div>
                                </div>
                            </div>

                            {/* Step 3 */}
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                                <div>
                                    <div className="inline-flex items-center justify-center h-14 w-14 rounded-full bg-brand-primary/10 mb-6">
                                        <span className="text-3xl">📈</span>
                                    </div>
                                    <h2 className="text-3xl font-bold text-gray-900 mb-4">3. Campaigns run and improve on their own</h2>
                                    <p className="text-lg text-gray-600 leading-relaxed mb-6">
                                        Once live, AI agents monitor the campaign every day — fixing disapproved ads, shifting budget to peak hours, and testing new ad variations. You get a weekly performance report. When the property sells, you pause with one click.
                                    </p>
                                    <ul className="space-y-3">
                                        {[
                                            'Daily bid and budget optimisation',
                                            'Disapproved ads fixed and resubmitted automatically',
                                            'Weekly email report — leads, clicks, cost per enquiry',
                                            'Pause instantly when the property sells',
                                        ].map((item) => (
                                            <li key={item} className="flex items-center text-gray-600">
                                                <svg className="h-5 w-5 text-brand-accent mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                                </svg>
                                                {item}
                                            </li>
                                        ))}
                                    </ul>
                                    <div className="mt-8">
                                        <a href="/register" className="inline-flex items-center justify-center px-8 py-4 text-lg font-bold rounded-xl text-white bg-brand-primary hover:bg-brand-dark shadow-lg transition-colors">
                                            Get started →
                                        </a>
                                        <p className="mt-3 text-sm text-gray-400">No credit card required</p>
                                    </div>
                                </div>
                                <div className="bg-brand-primary/5 border border-brand-primary/20 rounded-2xl p-8 flex items-center justify-center min-h-[300px]">
                                    <div className="text-center">
                                        <span className="text-7xl">📈</span>
                                        <p className="mt-4 text-brand-primary font-semibold">Improving every day</p>
                                        <p className="text-sm text-gray-500 mt-2">Launch → optimise → sell → pause</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* AI Agents */}
                    <div className="bg-gray-50 py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="text-center mb-14">
                                <h2 className="text-3xl sm:text-4xl font-bold text-gray-900">Six AI agents working for you</h2>
                                <p className="mt-4 text-lg text-gray-600 max-w-2xl mx-auto">
                                    Each one is specialised for a different part of getting your listing in front of the right buyers.
                                </p>
                            </div>
                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                                {agents.map((agent) => (
                                    <div key={agent.title} className="bg-white rounded-xl p-6 shadow-sm border border-gray-100">
                                        <div className="w-10 h-10 rounded-lg bg-brand-primary/10 flex items-center justify-center text-xl mb-4">
                                            {agent.emoji}
                                        </div>
                                        <h3 className="text-base font-semibold text-gray-900 mb-2">{agent.title}</h3>
                                        <p className="text-sm text-gray-600 leading-relaxed">{agent.description}</p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* CTA */}
                    <div className="bg-brand-primary py-16 sm:py-24">
                        <div className="max-w-3xl mx-auto text-center px-4 sm:px-6 lg:px-8">
                            <h2 className="text-3xl sm:text-4xl font-extrabold text-white">
                                Ready to launch your first listing campaign?
                            </h2>
                            <p className="mt-4 text-lg text-white/80">
                                Paste your first listing URL and your campaign is live in minutes.
                            </p>
                            <div className="mt-8 flex flex-col sm:flex-row gap-4 justify-center">
                                <a
                                    href="/register"
                                    className="inline-flex items-center justify-center px-8 py-4 rounded-lg text-lg font-semibold text-brand-primary bg-white hover:bg-gray-50 shadow-lg"
                                >
                                    Start your first campaign
                                </a>
                                <a
                                    href="/pricing"
                                    className="inline-flex items-center justify-center px-8 py-4 rounded-lg text-lg font-semibold text-white border-2 border-white/60 hover:border-white"
                                >
                                    View pricing
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
